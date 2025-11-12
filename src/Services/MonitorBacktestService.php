<?php

namespace SimpleTrader\Services;

use SimpleTrader\Database\MonitorRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Runner;

/**
 * Monitor Backtest Service
 *
 * Executes initial backtest for monitors and saves results
 */
class MonitorBacktestService
{
    private MonitorRepository $monitorRepository;
    private TickerRepository $tickerRepository;
    private QuoteRepository $quoteRepository;

    public function __construct(
        MonitorRepository $monitorRepository,
        TickerRepository $tickerRepository,
        QuoteRepository $quoteRepository
    ) {
        $this->monitorRepository = $monitorRepository;
        $this->tickerRepository = $tickerRepository;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Execute backtest for a monitor
     *
     * @param int $monitorId Monitor ID
     * @return bool Success status
     */
    public function executeBacktest(int $monitorId): bool
    {
        try {
            // Get monitor
            $monitor = $this->monitorRepository->getMonitor($monitorId);
            if (!$monitor) {
                throw new \RuntimeException("Monitor not found: {$monitorId}");
            }

            // Update status to running
            $this->updateProgress($monitorId, 0, 'running', null, null);

            // Get tickers
            $tickerIds = array_map('intval', explode(',', $monitor['tickers']));
            $tickers = [];
            foreach ($tickerIds as $tickerId) {
                $ticker = $this->tickerRepository->getTicker($tickerId);
                if ($ticker) {
                    $tickers[$tickerId] = $ticker;
                }
            }

            if (empty($tickers)) {
                throw new \RuntimeException("No valid tickers found for monitor");
            }

            // Get strategy class
            $strategyClass = $monitor['strategy_class'];
            if (!class_exists($strategyClass)) {
                throw new \RuntimeException("Strategy class not found: {$strategyClass}");
            }

            // Parse strategy parameters
            $strategyParams = [];
            if (!empty($monitor['strategy_parameters'])) {
                $strategyParams = json_decode($monitor['strategy_parameters'], true) ?? [];
            }

            // Create strategy instance
            $strategy = new $strategyClass(paramsOverrides: $strategyParams);
            $strategy->setCapital($monitor['initial_capital']);

            // Get quotes for all tickers
            $quotesData = [];
            foreach ($tickers as $tickerId => $ticker) {
                $quotes = $this->quoteRepository->getQuotes(
                    $tickerId,
                    $monitor['start_date'],
                    date('Y-m-d')
                );

                if (empty($quotes)) {
                    throw new \RuntimeException("No quotes found for ticker: {$ticker['symbol']}");
                }

                $quotesData[$tickerId] = array_reverse($quotes); // Reverse to get chronological order
            }

            // Create runner
            $runner = new Runner($strategy);

            // Add quotes to runner for each ticker
            foreach ($quotesData as $tickerId => $quotes) {
                $ticker = $tickers[$tickerId];
                foreach ($quotes as $quote) {
                    $runner->addBar(
                        $ticker['symbol'],
                        $quote['date'],
                        floatval($quote['open']),
                        floatval($quote['high']),
                        floatval($quote['low']),
                        floatval($quote['close']),
                        intval($quote['volume'])
                    );
                }
            }

            // Calculate total days for progress tracking
            $totalDays = 0;
            foreach ($quotesData as $quotes) {
                $totalDays = max($totalDays, count($quotes));
            }

            // Execute backtest
            $dayIndex = 0;
            $runner->execute(function($strategy) use ($monitorId, &$dayIndex, $totalDays, $tickers) {
                // Get current date from strategy
                $currentDate = date('Y-m-d');

                // Calculate progress
                $progress = $totalDays > 0 ? (int)(($dayIndex / $totalDays) * 100) : 0;

                // Update progress
                $this->updateProgress($monitorId, $progress, 'running', $currentDate, null);

                // Save daily snapshot
                $this->saveDailySnapshot($monitorId, $strategy, $currentDate, $tickers);

                $dayIndex++;
            });

            // Get final results
            $results = $runner->getResults();

            // Save trades
            $this->saveTrades($monitorId, $results, $tickers);

            // Calculate and save backtest metrics
            $this->saveBacktestMetrics($monitorId, $monitor, $results);

            // Mark backtest as completed
            $this->updateProgress($monitorId, 100, 'completed', date('Y-m-d'), null);
            $this->monitorRepository->markBacktestCompleted($monitorId, date('Y-m-d'));

            return true;

        } catch (\Exception $e) {
            // Log error and update status
            $errorMessage = $e->getMessage();
            $this->updateProgress($monitorId, 0, 'failed', null, $errorMessage);
            error_log("Monitor backtest failed for monitor {$monitorId}: {$errorMessage}");
            return false;
        }
    }

    /**
     * Update backtest progress
     */
    private function updateProgress(
        int $monitorId,
        int $progress,
        string $status,
        ?string $currentDate,
        ?string $error
    ): void {
        $this->monitorRepository->updateBacktestProgress(
            $monitorId,
            $progress,
            $status,
            $currentDate,
            $error
        );
    }

    /**
     * Save daily snapshot
     */
    private function saveDailySnapshot(
        int $monitorId,
        $strategy,
        string $date,
        array $tickers
    ): void {
        // Get current positions
        $positions = $strategy->getCurrentPositions();
        $positionsData = [];
        $positionsValue = 0;

        foreach ($positions as $position) {
            $positionData = [
                'ticker' => $position->getTicker(),
                'quantity' => $position->getQuantity(),
                'entry_price' => $position->getEntryPrice(),
                'current_price' => $position->getCurrentPrice(),
                'pnl' => $position->getPnl()
            ];
            $positionsData[] = $positionData;
            $positionsValue += $position->getQuantity() * $position->getCurrentPrice();
        }

        $equity = $strategy->getEquity();
        $cash = $strategy->getCash();
        $initialCapital = $strategy->getInitialCapital();

        $cumulativeReturn = (($equity - $initialCapital) / $initialCapital) * 100;

        // Get previous snapshot for daily return calculation
        $snapshots = $this->monitorRepository->getDailySnapshots($monitorId, 1);
        $dailyReturn = 0;
        if (!empty($snapshots)) {
            $prevEquity = $snapshots[0]['equity'];
            if ($prevEquity > 0) {
                $dailyReturn = (($equity - $prevEquity) / $prevEquity) * 100;
            }
        }

        $snapshot = [
            'date' => $date,
            'equity' => $equity,
            'cash' => $cash,
            'positions_value' => $positionsValue,
            'positions' => json_encode($positionsData),
            'strategy_state' => json_encode($strategy->getStrategyVariables()),
            'daily_return' => $dailyReturn,
            'cumulative_return' => $cumulativeReturn
        ];

        $this->monitorRepository->saveDailySnapshot($monitorId, $snapshot);
    }

    /**
     * Save trades from backtest results
     */
    private function saveTrades(int $monitorId, array $results, array $tickers): void
    {
        if (empty($results['trades'])) {
            return;
        }

        // Map ticker symbols to IDs
        $tickerSymbolMap = [];
        foreach ($tickers as $tickerId => $ticker) {
            $tickerSymbolMap[$ticker['symbol']] = $tickerId;
        }

        foreach ($results['trades'] as $trade) {
            $tickerSymbol = $trade['ticker'];
            $tickerId = $tickerSymbolMap[$tickerSymbol] ?? 0;

            $tradeData = [
                'date' => $trade['date'],
                'ticker_id' => $tickerId,
                'ticker_symbol' => $tickerSymbol,
                'action' => $trade['action'], // 'buy' or 'sell'
                'quantity' => $trade['quantity'],
                'price' => $trade['price'],
                'total_value' => $trade['quantity'] * $trade['price'],
                'commission' => $trade['commission'] ?? 0,
                'notes' => $trade['notes'] ?? null
            ];

            $this->monitorRepository->saveTrade($monitorId, $tradeData);
        }
    }

    /**
     * Calculate and save backtest metrics
     */
    private function saveBacktestMetrics(int $monitorId, array $monitor, array $results): void
    {
        $metrics = [
            'period_start' => $monitor['start_date'],
            'period_end' => date('Y-m-d'),
            'total_return' => $results['total_return'] ?? 0,
            'annualized_return' => $results['annualized_return'] ?? 0,
            'sharpe_ratio' => $results['sharpe_ratio'] ?? 0,
            'max_drawdown' => $results['max_drawdown'] ?? 0,
            'win_rate' => $results['win_rate'] ?? 0,
            'total_trades' => $results['total_trades'] ?? 0,
            'winning_trades' => $results['winning_trades'] ?? 0,
            'losing_trades' => $results['losing_trades'] ?? 0,
            'avg_win' => $results['avg_win'] ?? 0,
            'avg_loss' => $results['avg_loss'] ?? 0,
            'profit_factor' => $results['profit_factor'] ?? 0,
            'final_equity' => $results['final_equity'] ?? $monitor['initial_capital']
        ];

        $this->monitorRepository->saveMetrics($monitorId, 'backtest', $metrics);
    }
}
