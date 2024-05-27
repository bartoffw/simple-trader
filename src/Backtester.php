<?php

namespace SimpleTrader;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use MammothPHP\WoollyM\DataFrame;
use ReflectionException;
use ReflectionMethod;
use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Exceptions\StrategyException;
use SimpleTrader\Helpers\Calculator;
use SimpleTrader\Helpers\OptimizationParam;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Helpers\Side;
use SimpleTrader\Loggers\LoggerInterface;

class Backtester
{
    protected ?LoggerInterface $logger = null;
    protected BaseStrategy $strategy;
    protected ?array $strategiesOptimized = null;
    protected Assets $assets;
    protected ?string $benchmarkTicker = null;
    protected ?Assets $benchmark = null;
    protected Carbon $backtestStartTime;
    protected ?Carbon $backtestEndTime;
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
    protected ?Carbon $lastPeakDate = null;
    protected int $maxBarsInDrawdown = 0;
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
        if (empty($strategy->getTickers())) {
            throw new BacktesterException('Strategy tickers not set.');
        }
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

    public function getStrategies(): ?array
    {
        return $this->strategiesOptimized;
    }

    public function getBenchmarkTicker(): string
    {
        return $this->benchmarkTicker;
    }

    public function getBacktestStartTime(): Carbon
    {
        return $this->backtestStartTime;
    }

    public function getBacktestEndTime(): Carbon
    {
        return $this->backtestEndTime;
    }

    public function getLastBacktestTime(): string
    {
        return $this->lastBacktestTime;
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
        $this->logger?->logDebug('Benchmark [' . $position->getCloseTime()->toDateString() . ']: ' . $benchmarkResult);
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
            $this->lastPeakDate = $position->getCloseTime();
        }
        if ($currentCapital < $this->peakValue) {
            $currentDrawdownValue = $this->peakValue - $currentCapital;
            $currentDrawdownPercent = ($this->peakValue - $currentCapital) * 100 / $this->peakValue;
            if ($this->lastPeakDate) {
                $barsInDrawdown = $this->lastPeakDate->diffInDays($position->getCloseTime());
                if ($barsInDrawdown > $this->maxBarsInDrawdown) {
                    $this->maxBarsInDrawdown = $barsInDrawdown;
                }
            }
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

    protected function calcStatVolatility(array $resultLog, int $tradeCount): float
    {
        // TODO: review this in the future
        if (count($resultLog) > 2) {
            $mean = array_sum($resultLog) / count($resultLog);
            return Calculator::stdDev($resultLog) * 100 / $mean;
        }
        return 0.00;
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

    protected function combinations(array $arrays): array
    {
        $result = [];
        $iterations = 1;
        foreach ($arrays as $paramValues) {
            $iterations *= count($paramValues);
        }
        uasort($arrays, function($a, $b) {
            return -(count($a) <=> count($b));
        });
        $limit = $iterations;
        foreach ($arrays as $paramName => $paramValues) {
            $limit /= count($paramValues);
            for ($i = 0; $i < $iterations; $i++) {
                $result[$i][$paramName] = $paramValues[floor($i / $limit) % count($paramValues)];
            }
        }
        return $result;
    }

    protected function createStrategyParams(array $optimizationParams): array
    {
        if (empty($optimizationParams)) {
            // just use the default parameters
            return $this->strategy->getParameters();
        } else {
            $paramsList = [];
            /** @var OptimizationParam $param */
            foreach ($optimizationParams as $param) {
                $paramsList[$param->getParamName()] = $param->getValues();
            }
            return $this->combinations($paramsList);
        }
    }

    public function getTradeStats(BaseStrategy $strategy, array $tradeLog)
    {
        $currentCapital = $strategy->getInitialCapital();

        $this->capitalLog = [ $currentCapital ];
        $this->positionDrawdownLog = [ 0.00 ];
        $benchmarkLog = [ $currentCapital ];

        $benchmarkQty = $this->benchmark !== null ? $this->calcStatBenchmarkQty($currentCapital) : null;
        $peakValue = $currentCapital;
        $troughValue = $currentCapital;

        $avgProfit = $strategy->getAvgProfit();
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
            'net_profit' => $strategy->getProfit(),
            'net_profit_percent' => $strategy->getProfitPercent(),
            'net_profit_longs' => $strategy->getNetProfitLongs(),
            'net_profit_shorts' => $strategy->getNetProfitShorts(),
            'avg_profit' => $avgProfit,
            'avg_profit_longs' => $strategy->getAvgProfitLongs(),
            'avg_profit_shorts' => $strategy->getAvgProfitShorts(),
            'gross_profit' => $strategy->getGrossProfit(),
            'gross_profit_longs' => $strategy->getGrossProfitLongs(),
            'gross_profit_shorts' => $strategy->getGrossProfitShorts(),
            'gross_loss' => $strategy->getGrossLoss(),
            'gross_loss_longs' => $strategy->getGrossLossLongs(),
            'gross_loss_shorts' => $strategy->getGrossLossShorts(),
            'profitable_transactions' => count($tradeLog) > 0 ? $strategy->getProfitableTransactions() * 100 / count($tradeLog) -1 : 0,
            'profit_factor' => $strategy->getProfitFactor(),
            'profit_factor_longs' => $strategy->getProfitFactorLongs(),
            'profit_factor_shorts' => $strategy->getProfitFactorShorts(),
            'volatility' => $this->calcStatVolatility($resultLog, count($tradeLog)),
            'sharpe_ratio' => $this->calcStatSharpeRatio($resultLog, $avgProfit, count($tradeLog)),
            'max_quantity_longs' => $this->maxQuantityLongs,
            'max_quantity_shorts' => $this->maxQuantityShorts,
            'max_strategy_drawdown_value' => $this->maxStrategyDrawdownValue,
            'max_strategy_drawdown_percent' => $this->maxStrategyDrawdownPercent,
            'max_position_drawdown_value' => $this->maxPositionDrawdownValue,
            'max_position_drawdown_percent' => $this->maxPositionDrawdownPercent,
            'max_bars_in_drawdown' => $this->maxBarsInDrawdown,
            'peak_value' => $peakValue,
            'trough_value' => $troughValue,
            'profitable_transactions_count' => $strategy->getProfitableTransactions(),
            'profitable_transactions_long_count' => $strategy->getProfitableTransactionsLongs(),
            'profitable_transactions_short_count' => $strategy->getProfitableTransactionsShorts(),
            'losing_transactions_count' => $strategy->getLosingTransactions(),
            'losing_transactions_long_count' => $strategy->getLosingTransactionsLongs(),
            'losing_transactions_short_count' => $strategy->getLosingTransactionsShorts(),
            'avg_profitable_transaction' => $strategy->getAvgProfitableTransaction(),
            'avg_profitable_transaction_longs' => $strategy->getAvgProfitableTransactionLongs(),
            'avg_profitable_transaction_shorts' => $strategy->getAvgProfitableTransactionShorts(),
            'avg_losing_transaction' => $strategy->getAvgLosingTransaction(),
            'avg_losing_transaction_longs' => $strategy->getAvgLosingTransactionLongs(),
            'avg_losing_transaction_shorts' => $strategy->getAvgLosingTransactionShorts(),
            'max_profitable_transaction' => $strategy->getMaxProfitableTransaction(),
            'max_profitable_transaction_longs' => $strategy->getMaxProfitableTransactionLongs(),
            'max_profitable_transaction_shorts' => $strategy->getMaxProfitableTransactionShorts(),
            'max_losing_transaction' => $strategy->getMaxLosingTransaction(),
            'max_losing_transaction_longs' => $strategy->getMaxLosingTransactionLongs(),
            'max_losing_transaction_shorts' => $strategy->getMaxLosingTransactionShorts(),
            'avg_bars_transaction' => $strategy->getAvgOpenBars(),
            'avg_bars_transaction_longs' => $strategy->getAvgOpenBarsLongs(),
            'avg_bars_transaction_shorts' => $strategy->getAvgOpenBarsShorts(),
            'avg_bars_profitable_transaction' => $strategy->getAvgProfitableOpenBars(),
            'avg_bars_profitable_transaction_longs' => $strategy->getAvgProfitableOpenBarsLongs(),
            'avg_bars_profitable_transaction_shorts' => $strategy->getAvgProfitableOpenBarsShorts(),
            'avg_bars_losing_transaction' => $strategy->getAvgLosingOpenBars(),
            'avg_bars_losing_transaction_longs' => $strategy->getAvgLosingOpenBarsLongs(),
            'avg_bars_losing_transaction_shorts' => $strategy->getAvgLosingOpenBarsShorts()
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
    public function runBacktest(Assets $assets, Carbon $startTime, ?Carbon $endTime = null, array $optimizationParameters = []): void
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
        $this->strategy->setTickers($assets->getTickers());
        $this->strategy->setStartDate($startTime);
        $this->backtestStartTime = $startTime;
        $this->backtestEndTime = $endTime;
        $this->backtestStarted = microtime(true);

        $backtestStartTime = $this->strategy->getStartDateForCalculations($this->assets, $startTime);

        $onOpenExists = (new ReflectionMethod($this->strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
        $onCloseExists = (new ReflectionMethod($this->strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;

        $this->logger?->logInfo('Starting the backtest. Start date: ' . $startTime->toDateString() . ', end date: ' . ($endTime ? $endTime->toDateString() : 'none'));

        if (empty($optimizationParameters)) {
            $this->runBacktestPass($backtestStartTime, $this->strategy, $onOpenExists, $onCloseExists, $startTime, $endTime);
        } else {
            $strategyParams = $this->createStrategyParams($optimizationParameters);
            $this->logger?->logInfo('Backtesting ' . count($strategyParams) . ' iterations');
            foreach ($strategyParams as $i => $paramGroup) {
                $this->logger?->logInfo('Running iteration #' . ($i + 1) . ', params: ' . implode(', ', $paramGroup));
                $strategy = clone $this->strategy;
                $startTimePass = clone $startTime;
                $strategy->setParameters($paramGroup);
                $this->runBacktestPass($backtestStartTime, $strategy, $onOpenExists, $onCloseExists, $startTimePass, $endTime);
                $this->strategiesOptimized[] = $strategy;
            }
        }

        $this->backtestFinished = microtime(true);
        $this->lastBacktestTime = $this->backtestFinished - $this->backtestStarted;
    }

    protected function runBacktestPass(Carbon $backtestStartTime, BaseStrategy $strategy, bool $onOpenExists, bool $onCloseExists,
                                       Carbon $startTime, ?Carbon $endTime = null): void
    {
        $currentDateTime = $startTime->copy();
        while ($endTime === null || $currentDateTime <= $endTime) {
            $this->logger?->logDebug('Backtest day: ' . $currentDateTime->toDateString());
            $currentAssets = $this->assets->cloneToDate($backtestStartTime, $currentDateTime);

            if ($onOpenExists) {
                $strategy->onOpen($currentAssets, $currentDateTime);
            }
            if ($onCloseExists) {
                $strategy->onClose($currentAssets, $currentDateTime);
            }
            unset($currentAssets);
            /** @var Position $position */
            foreach ($strategy->getOpenTrades() as $position) {
                $position->incrementOpenBars();
            }
            // TODO: add support for periods other than daily
            $currentDateTime->addDay();
        }
        $strategy->onStrategyEnd($this->assets->cloneToDate($backtestStartTime, $currentDateTime), $currentDateTime);
    }
}