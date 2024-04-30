<?php

namespace SimpleTrader;

use MammothPHP\WoollyM\DataFrame;
use ReflectionException;
use ReflectionMethod;
use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Calculator;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Loggers\LoggerInterface;

class Backtester
{
    protected ?LoggerInterface $logger = null;
    protected BaseStrategy $strategy;
    protected Assets $assets;
    protected ?string $benchmarkTicker = null;
    protected ?Assets $benchmark = null;
    protected DateTime $backtestStartTime;
    protected ?DateTime $backtestEndTime;
    protected string $backtestStarted;
    protected string $backtestFinished;
    protected string $lastBacktestTime;

    protected float $maxQuantityLongs = 0.00;
    protected float $maxQuantityShorts = 0.00;
    protected float $peakValue = 0.00;
    protected float $maxStrategyDrawdownValue = 0.00;
    protected float $maxStrategyDrawdownPercent = 0.00;
    protected float $maxPositionDrawdownValue = 0.00;
    protected float $maxPositionDrawdownPercent = 0.00;
    protected array $capitalLog = [];
    protected array $positionDrawdownLog = [];


    public function __construct(protected Resolution $resolution)
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setStrategy(BaseStrategy $strategy): void
    {
        $this->strategy = $strategy;
    }

    public function setBenchmark(DataFrame $asset, string $ticker): void
    {
        $assets = new Assets();
        $assets->addAsset($asset, $ticker);
        $this->benchmark = $assets;
        $this->benchmarkTicker = $ticker;
    }

    public function getStrategy(): BaseStrategy
    {
        return $this->strategy;
    }

    public function getStrategyName(): string
    {
        return $this->strategy->getStrategyName();
    }

    public function getBenchmarkTicker(): string
    {
        return $this->benchmarkTicker;
    }

    public function getBacktestStartTime(): DateTime
    {
        return $this->backtestStartTime;
    }

    public function getBacktestEndTime(): DateTime
    {
        return $this->backtestEndTime;
    }

    public function getLastBacktestTime(): string
    {
        return $this->lastBacktestTime;
    }

    public function getTradeLog(): array
    {
        return $this->strategy->getTradeLog();
    }

    public function getAssets(): Assets
    {
        return $this->assets;
    }

    protected function calcStatMaxQty(Position $position): void
    {
        $quantity = $position->getQuantity();
        if ($position->getSide() === Side::Long && $quantity > $this->maxQuantityLongs /*Calculator::compare($quantity, $maxQuantityLongs) > 0*/) {
            $this->maxQuantityLongs = $position->getQuantity();
        }
        if ($position->getSide() === Side::Short && $quantity > $this->maxQuantityShorts /*Calculator::compare($quantity, $maxQuantityShorts) > 0*/) {
            $this->maxQuantityShorts = $position->getQuantity();
        }
    }

    protected function calcStatBenchmarkQty($currentCapital): float
    {
        $benchmarkLoaded = $this->benchmark->cloneToDate(
            $this->strategy->getStartDateForCalculations($this->assets, $this->strategy->getStartDate()),
            $this->strategy->getStartDate()
        );
        $benchmarkValue = $benchmarkLoaded->getCurrentValue($this->benchmarkTicker);
        $benchmarkQty = $benchmarkValue ? $currentCapital / $benchmarkValue : 0;
        unset($benchmarkLoaded);
        return $benchmarkQty;
    }

    protected function calcStatBenchmarkLogEntry(Position $position, float $benchmarkQty): float
    {
        $benchmarkLoaded = $this->benchmark->cloneToDate($this->strategy->getStartDate(), $position->getCloseTime());
        $benchmarkValue = $benchmarkLoaded->getCurrentValue($this->benchmarkTicker);
        $benchmarkResult = round($benchmarkValue * $benchmarkQty, 2);
        unset($benchmarkLoaded);
//                echo "{$position->getCloseTime()}: $benchmarkValue\n";
        return $benchmarkResult;
    }

    protected function calcStatProfitLoss(float $profit, float $currentCapital): float
    {
        if ($profit > 0.00001) {
            $currentCapital += $profit; // Calculator::calculate('$1 + $2', $currentCapital, $profit);
        } else {
            $loss = abs($profit);
            $currentCapital -= $loss; // Calculator::calculate('$1 - $2', $currentCapital, $loss);
        }
        return $currentCapital;
    }

    protected function calcStatStrategyDrawdown(float $currentCapital, Position $position)
    {
        if ($currentCapital > $this->peakValue) {
            $this->peakValue = $currentCapital;
        }
        if ($currentCapital < $this->peakValue) {
            $currentDrawdownValue = $this->peakValue - $currentCapital;
            $currentDrawdownPercent = ($this->peakValue - $currentCapital) * 100 / $this->peakValue;
        } else {
            $currentDrawdownValue = 0.00;
            $currentDrawdownPercent = 0.00;
        }
        if ($currentDrawdownValue > $this->maxStrategyDrawdownValue) {
            $this->maxStrategyDrawdownValue = $currentDrawdownValue;
            $this->maxStrategyDrawdownPercent = $currentDrawdownPercent;
        }
        $position->setStrategyDrawdown($currentDrawdownValue, $currentDrawdownPercent);
    }

    protected function calcStatPositionDrawdown(Position $position)
    {
        if ($position->getMaxDrawdownValue() > $this->maxPositionDrawdownValue) {
            $this->maxPositionDrawdownValue = $position->getMaxDrawdownValue();
            $this->maxPositionDrawdownPercent = $position->getMaxDrawdownPercent();
        }
    }

    protected function calcStatBenchmarkProfit(array $benchmarkLog): float
    {
        return empty($benchmarkLog) ? 0.00 : $benchmarkLog[count($benchmarkLog) - 1] - $benchmarkLog[0];
    }

    protected function calcStatSharpeRatio(array $resultLog, float $avgProfit, int $tradeCount): float
    {
        if (count($resultLog) > 2) {
            $stdDev = Calculator::stdDev($resultLog);
            return sqrt($tradeCount) * $avgProfit / $stdDev;
            //Calculator::calculate('sqrt($1) * $2 / $3', count($tradeLog), $avgProfit, $stdDev);
        }
        return 0.00;
    }

    public function getTradeStats(array $tradeLog)
    {
        $currentCapital = $this->strategy->getInitialCapital();

        $this->capitalLog = [ $currentCapital ];
        $this->positionDrawdownLog = [ 0.00 ];
        $benchmarkLog = [ $currentCapital ];

        $benchmarkQty = $this->benchmark !== null ? $this->calcStatBenchmarkQty($currentCapital) : null;
        $peakValue = $currentCapital;
        $troughValue = $currentCapital;

        $avgProfit = $this->strategy->getAvgProfit();
        $resultLog = [];

        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();

            $this->calcStatMaxQty($position);
            $currentCapital = $this->calcStatProfitLoss($profit, $currentCapital);
            $position->setPortfolioBalance($currentCapital);
            $this->calcStatStrategyDrawdown($currentCapital, $position);
            $this->calcStatPositionDrawdown($position);

            $resultLog[] = $profit;
            $this->capitalLog[] = $currentCapital;
            $this->positionDrawdownLog[] = empty($position->getMaxDrawdownPercent()) ? 0 : 0 - round($position->getMaxDrawdownPercent(), 1);
            if ($this->benchmark !== null) {
                $benchmarkLog[] = $this->calcStatBenchmarkLogEntry($position, $benchmarkQty);
            }
        }

        $params = [
            'capital_log' => $this->capitalLog,
            'position_drawdown_log' => $this->positionDrawdownLog,
            'net_profit' => $this->strategy->getProfit(),
            'net_profit_percent' => $this->strategy->getProfitPercent(),
            'net_profit_longs' => $this->strategy->getNetProfitLongs(),
            'net_profit_shorts' => $this->strategy->getNetProfitShorts(),
            'avg_profit' => $avgProfit,
            'avg_profit_longs' => $this->strategy->getAvgProfitLongs(),
            'avg_profit_shorts' => $this->strategy->getAvgProfitShorts(),
            'gross_profit' => $this->strategy->getGrossProfit(),
            'gross_profit_longs' => $this->strategy->getGrossProfitLongs(),
            'gross_profit_shorts' => $this->strategy->getGrossProfitShorts(),
            'gross_loss' => $this->strategy->getGrossLoss(),
            'gross_loss_longs' => $this->strategy->getGrossLossLongs(),
            'gross_loss_shorts' => $this->strategy->getGrossLossShorts(),
            'profitable_transactions' => count($tradeLog) > 0 ? $this->strategy->getProfitableTransactions() * 100 / count($tradeLog) -1 : 0,
            'profit_factor' => $this->strategy->getProfitFactor(),
            'profit_factor_longs' => $this->strategy->getProfitFactorLongs(),
            'profit_factor_shorts' => $this->strategy->getProfitFactorShorts(),
            'sharpe_ratio' => $this->calcStatSharpeRatio($resultLog, $avgProfit, count($tradeLog)),
            'max_quantity_longs' => $this->maxQuantityLongs,
            'max_quantity_shorts' => $this->maxQuantityShorts,
            'max_strategy_drawdown_value' => $this->maxStrategyDrawdownValue,
            'max_strategy_drawdown_percent' => $this->maxStrategyDrawdownPercent,
            'max_position_drawdown_value' => $this->maxPositionDrawdownValue,
            'max_position_drawdown_percent' => $this->maxPositionDrawdownPercent,
            'peak_value' => $peakValue,
            'trough_value' => $troughValue,
            'profitable_transactions_count' => $this->strategy->getProfitableTransactions(),
            'profitable_transactions_long_count' => $this->strategy->getProfitableTransactionsLongs(),
            'profitable_transactions_short_count' => $this->strategy->getProfitableTransactionsShorts(),
            'losing_transactions_count' => $this->strategy->getLosingTransactions(),
            'losing_transactions_long_count' => $this->strategy->getLosingTransactionsLongs(),
            'losing_transactions_short_count' => $this->strategy->getLosingTransactionsShorts(),
            'avg_profitable_transaction' => $this->strategy->getAvgProfitableTransaction(),
            'avg_profitable_transaction_longs' => $this->strategy->getAvgProfitableTransactionLongs(),
            'avg_profitable_transaction_shorts' => $this->strategy->getAvgProfitableTransactionShorts(),
            'avg_losing_transaction' => $this->strategy->getAvgLosingTransaction(),
            'avg_losing_transaction_longs' => $this->strategy->getAvgLosingTransactionLongs(),
            'avg_losing_transaction_shorts' => $this->strategy->getAvgLosingTransactionShorts(),
            'max_profitable_transaction' => $this->strategy->getMaxProfitableTransaction(),
            'max_profitable_transaction_longs' => $this->strategy->getMaxProfitableTransactionLongs(),
            'max_profitable_transaction_shorts' => $this->strategy->getMaxProfitableTransactionShorts(),
            'max_losing_transaction' => $this->strategy->getMaxLosingTransaction(),
            'max_losing_transaction_longs' => $this->strategy->getMaxLosingTransactionLongs(),
            'max_losing_transaction_shorts' => $this->strategy->getMaxLosingTransactionShorts(),
            'avg_bars_transaction' => $this->strategy->getAvgOpenBars(),
            'avg_bars_transaction_longs' => $this->strategy->getAvgOpenBarsLongs(),
            'avg_bars_transaction_shorts' => $this->strategy->getAvgOpenBarsShorts(),
            'avg_bars_profitable_transaction' => $this->strategy->getAvgProfitableOpenBars(),
            'avg_bars_profitable_transaction_longs' => $this->strategy->getAvgProfitableOpenBarsLongs(),
            'avg_bars_profitable_transaction_shorts' => $this->strategy->getAvgProfitableOpenBarsShorts(),
            'avg_bars_losing_transaction' => $this->strategy->getAvgLosingOpenBars(),
            'avg_bars_losing_transaction_longs' => $this->strategy->getAvgLosingOpenBarsLongs(),
            'avg_bars_losing_transaction_shorts' => $this->strategy->getAvgLosingOpenBarsShorts()
        ];
        if ($this->benchmark !== null) {
            $params['benchmark_log'] = $benchmarkLog;
            $params['benchmark_profit'] = $this->calcStatBenchmarkProfit($benchmarkLog);
        }
        return $params;
    }

    /**
     * @throws ReflectionException
     * @throws LoaderException
     * @throws BacktesterException
     * @throws StrategyException
     */
    public function runBacktest(Assets $assets, DateTime $startTime, ?DateTime $endTime = null, array $optimizationParameters = []): void
    {
        if (!isset($this->strategy)) {
            throw new BacktesterException('Strategy is not set');
        }
        if ($assets->isEmpty()) {
            throw new BacktesterException('No assets defined');
        }
        if (empty($this->strategy->getCapital())) {
            throw new BacktesterException('No capital set');
        }
        $this->assets = $assets;
        $this->strategy->setLogger($this->logger);
        $this->strategy->setStartDate($startTime);
        $this->backtestStartTime = $startTime;
        $this->backtestEndTime = $endTime;
        $this->backtestStarted = microtime(true);

        $backtestStartTime = $this->strategy->getStartDateForCalculations($this->assets, $startTime);

        $onOpenExists = (new ReflectionMethod($this->strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
        $onCloseExists = (new ReflectionMethod($this->strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;

        $this->logger?->logInfo('Starting the backtest. Start date: ' . $startTime->getDateTime() . ', end date: ' . ($endTime ? $endTime->getDateTime() : 'none'));
//        $currentAssets = null;
        while ($endTime === null || $startTime->getCurrentDateTime() <= $endTime->getDateTime()) {
            $this->logger?->logDebug('Backtest day: ' . $startTime->getCurrentDateTime());
            $currentDateTime = new DateTime($startTime->getCurrentDateTime());
            $currentAssets = $this->assets->cloneToDate($backtestStartTime, $currentDateTime);

            if ($onOpenExists) {
                $this->strategy->onOpen($currentAssets, $currentDateTime);
            }
            if ($onCloseExists) {
                $this->strategy->onClose($currentAssets, $currentDateTime);
            }
            unset($currentAssets);
            /** @var Position $position */
            foreach ($this->strategy->getOpenTrades() as $position) {
                $position->incrementOpenBars();
            }
            $startTime->increaseByStep();
            // TODO
        }
        $currentDateTime = new DateTime($startTime->getCurrentDateTime());
        $this->strategy->onStrategyEnd($this->assets->cloneToDate($backtestStartTime, $currentDateTime), $currentDateTime);

        $this->backtestFinished = microtime(true);
        $this->lastBacktestTime = $this->backtestFinished - $this->backtestStarted;
    }
}