<?php

namespace SimpleTrader\Services;

use SimpleTrader\Database\MonitorRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Loggers\LoggerInterface;
use SimpleTrader\Runner;

/**
 * Monitor Update Service
 *
 * Handles daily updates of active strategy monitors
 */
class MonitorUpdateService
{
    private MonitorRepository $monitorRepository;
    private TickerRepository $tickerRepository;
    private QuoteRepository $quoteRepository;
    private ?LoggerInterface $logger = null;

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
     * Set logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Update all active monitors for a specific date (default: today)
     *
     * @param string|null $date Date to process (Y-m-d), defaults to today
     * @return array ['success' => int, 'failed' => int, 'skipped' => int, 'errors' => array]
     */
    public function updateAllMonitors(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $monitors = $this->monitorRepository->getMonitorsByStatus('active');

        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total' => count($monitors),
            'errors' => [],
            'details' => []
        ];

        $this->log("Starting monitor update for {$results['total']} monitors on {$date}");

        foreach ($monitors as $monitor) {
            try {
                $result = $this->updateMonitor($monitor['id'], $date);

                if ($result['success']) {
                    $results['success']++;
                    $this->log("✓ Monitor {$monitor['id']} ({$monitor['name']}): {$result['message']}");

                    $results['details'][] = [
                        'monitor_id' => $monitor['id'],
                        'monitor_name' => $monitor['name'],
                        'status' => 'success',
                        'message' => $result['message']
                    ];
                } else {
                    if ($result['skipped']) {
                        $results['skipped']++;
                        $this->log("⊘ Monitor {$monitor['id']} ({$monitor['name']}): {$result['message']}");
                    } else {
                        $results['failed']++;
                        $errorMsg = "✗ Monitor {$monitor['id']} ({$monitor['name']}): {$result['message']}";
                        $this->log($errorMsg, 'error');
                        $results['errors'][] = $errorMsg;
                    }

                    $results['details'][] = [
                        'monitor_id' => $monitor['id'],
                        'monitor_name' => $monitor['name'],
                        'status' => $result['skipped'] ? 'skipped' : 'failed',
                        'message' => $result['message']
                    ];
                }

            } catch (\Exception $e) {
                $results['failed']++;
                $errorMsg = "✗ Monitor {$monitor['id']} ({$monitor['name']}): Exception - " . $e->getMessage();
                $this->log($errorMsg, 'error');
                $results['errors'][] = $errorMsg;

                $results['details'][] = [
                    'monitor_id' => $monitor['id'],
                    'monitor_name' => $monitor['name'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        $this->log("Monitor update completed: {$results['success']} succeeded, {$results['failed']} failed, {$results['skipped']} skipped");

        return $results;
    }

    /**
     * Update specific monitor for a specific date
     *
     * @param int $monitorId Monitor ID
     * @param string|null $date Date to process (Y-m-d), defaults to today
     * @return array ['success' => bool, 'message' => string, 'skipped' => bool]
     */
    public function updateMonitor(int $monitorId, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');

        try {
            // Get monitor
            $monitor = $this->monitorRepository->getMonitor($monitorId);
            if (!$monitor) {
                return [
                    'success' => false,
                    'message' => 'Monitor not found',
                    'skipped' => false
                ];
            }

            // Check if monitor is active
            if ($monitor['status'] !== 'active') {
                return [
                    'success' => false,
                    'message' => "Monitor status is '{$monitor['status']}'",
                    'skipped' => true
                ];
            }

            // Check if already processed today
            $lastProcessed = $monitor['last_processed_date'];
            if ($lastProcessed && $lastProcessed >= $date) {
                return [
                    'success' => false,
                    'message' => "Already processed for {$date}",
                    'skipped' => true
                ];
            }

            $this->log("Processing monitor {$monitorId} ({$monitor['name']}) for {$date}");

            // Get tickers
            $tickerIds = array_map('intval', explode(',', $monitor['tickers']));
            $tickers = [];
            $tickerSymbols = [];
            foreach ($tickerIds as $tickerId) {
                $ticker = $this->tickerRepository->getTicker($tickerId);
                if ($ticker) {
                    $tickers[$tickerId] = $ticker;
                    $tickerSymbols[] = $ticker['symbol'];
                }
            }

            if (empty($tickers)) {
                throw new \RuntimeException("No valid tickers found");
            }

            // Check if quotes exist for the date
            $quotesAvailable = true;
            foreach ($tickers as $tickerId => $ticker) {
                $quotes = $this->quoteRepository->getQuotes($tickerId, $date, $date);
                if (empty($quotes)) {
                    $quotesAvailable = false;
                    break;
                }
            }

            if (!$quotesAvailable) {
                return [
                    'success' => false,
                    'message' => "No quotes available for {$date}",
                    'skipped' => true
                ];
            }

            // Get strategy class and parameters
            $strategyClass = $monitor['strategy_class'];
            if (!class_exists($strategyClass)) {
                throw new \RuntimeException("Strategy class not found: {$strategyClass}");
            }

            $strategyParams = [];
            if (!empty($monitor['strategy_parameters'])) {
                $strategyParams = json_decode($monitor['strategy_parameters'], true) ?? [];
            }

            // Create strategy instance
            $strategy = new $strategyClass(paramsOverrides: $strategyParams);
            $strategy->setCapital($monitor['initial_capital']);

            // Load previous state
            $this->loadPreviousState($monitorId, $strategy);

            // Get the date range (lookback period to current date)
            $lookbackPeriod = $strategy->getMaxLookbackPeriod();
            $startDate = date('Y-m-d', strtotime($date . ' - ' . $lookbackPeriod . ' days'));

            // Create runner and add bars
            $runner = new Runner($strategy);

            foreach ($tickers as $tickerId => $ticker) {
                $quotes = $this->quoteRepository->getQuotes($tickerId, $startDate, $date);
                $quotes = array_reverse($quotes); // Chronological order

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

            // Execute strategy
            $runner->execute(function($strategy) use ($monitorId, $date, $tickers) {
                // Save daily snapshot
                $this->saveDailySnapshot($monitorId, $strategy, $date, $tickers);
            });

            // Get and save trades
            $results = $runner->getResults();
            if (!empty($results['trades'])) {
                $this->saveTrades($monitorId, $results['trades'], $tickers, $date);
            }

            // Update last processed date
            $this->monitorRepository->updateLastProcessedDate($monitorId, $date);

            // Update forward test metrics
            $this->updateForwardMetrics($monitorId, $monitor);

            return [
                'success' => true,
                'message' => "Processed successfully",
                'skipped' => false
            ];

        } catch (\Exception $e) {
            $this->log("Error processing monitor {$monitorId}: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Load previous state from database into strategy
     */
    private function loadPreviousState(int $monitorId, $strategy): void
    {
        $latestSnapshot = $this->monitorRepository->getLatestSnapshot($monitorId);

        if ($latestSnapshot) {
            // Restore strategy state
            if (!empty($latestSnapshot['strategy_state'])) {
                $strategyState = json_decode($latestSnapshot['strategy_state'], true);
                if ($strategyState) {
                    $strategy->setStrategyVariables($strategyState);
                }
            }

            // Restore positions
            if (!empty($latestSnapshot['positions'])) {
                $positions = json_decode($latestSnapshot['positions'], true);
                // Note: This may need adjustment based on how your strategy stores positions
                // For now, we'll let the strategy rebuild from its state
            }

            $this->log("Loaded previous state from {$latestSnapshot['date']}");
        }
    }

    /**
     * Save daily snapshot
     */
    private function saveDailySnapshot(int $monitorId, $strategy, string $date, array $tickers): void
    {
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

        // Get previous snapshot for daily return
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
     * Save trades for specific date
     */
    private function saveTrades(int $monitorId, array $trades, array $tickers, string $date): void
    {
        // Map ticker symbols to IDs
        $tickerSymbolMap = [];
        foreach ($tickers as $tickerId => $ticker) {
            $tickerSymbolMap[$ticker['symbol']] = $tickerId;
        }

        foreach ($trades as $trade) {
            // Only save trades for the current date
            if ($trade['date'] !== $date) {
                continue;
            }

            $tickerSymbol = $trade['ticker'];
            $tickerId = $tickerSymbolMap[$tickerSymbol] ?? 0;

            $tradeData = [
                'date' => $trade['date'],
                'ticker_id' => $tickerId,
                'ticker_symbol' => $tickerSymbol,
                'action' => $trade['action'],
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
     * Update forward test metrics
     */
    private function updateForwardMetrics(int $monitorId, array $monitor): void
    {
        // Get all snapshots since backtest completed
        $backtestEndDate = $monitor['start_date'];

        // Calculate metrics from trades and snapshots
        // This is a simplified version - you may want more sophisticated calculations
        $trades = $this->monitorRepository->getTrades($monitorId);
        $snapshots = $this->monitorRepository->getDailySnapshots($monitorId);

        if (empty($snapshots)) {
            return;
        }

        $firstSnapshot = end($snapshots);
        $lastSnapshot = $snapshots[0];

        $totalReturn = $lastSnapshot['cumulative_return'] ?? 0;

        // Calculate other metrics (simplified)
        $winningTrades = 0;
        $losingTrades = 0;
        foreach ($trades as $trade) {
            // This is simplified - real calculation would need to match buy/sell pairs
            if ($trade['action'] === 'sell') {
                // Simplified P&L calculation
                $losingTrades++; // Placeholder
            }
        }

        $metrics = [
            'period_start' => $firstSnapshot['date'],
            'period_end' => $lastSnapshot['date'],
            'total_return' => $totalReturn,
            'annualized_return' => 0, // Calculate if needed
            'sharpe_ratio' => 0, // Calculate if needed
            'max_drawdown' => 0, // Calculate if needed
            'win_rate' => 0,
            'total_trades' => count($trades),
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'avg_win' => 0,
            'avg_loss' => 0,
            'profit_factor' => 0,
            'final_equity' => $lastSnapshot['equity']
        ];

        $this->monitorRepository->saveMetrics($monitorId, 'forward', $metrics);
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void
    {
        if ($this->logger) {
            switch ($level) {
                case 'error':
                    $this->logger->logError($message);
                    break;
                case 'warning':
                    $this->logger->logWarning($message);
                    break;
                case 'debug':
                    $this->logger->logDebug($message);
                    break;
                default:
                    $this->logger->logInfo($message);
            }
        }
    }
}
