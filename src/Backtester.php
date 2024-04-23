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

    protected float $profits = 0.00;
    protected float $losses = 0.00;
    protected $maxQuantityLongs = 0;
    protected $maxQuantityShorts = 0;
    protected int $profitableTransactionsLong = 0;
    protected int $profitableTransactionsShort = 0;
    protected int $losingTransactionsLong = 0;
    protected int $losingTransactionsShort = 0;
    protected array $capitalLog = [];
    protected array $drawdownLog = [];
    protected array $benchmarkLog = [];


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

    public function getLastBacktestTime(): string
    {
        return $this->lastBacktestTime;
    }

    public function getTradeLog(): array
    {
        return $this->strategy->getTradeLog();
    }

    public function getProfit(): float
    {
        return $this->strategy->getCapital() - $this->strategy->getInitialCapital();
        //Calculator::calculate('$1 - $2', $this->strategy->getCapital(), $this->strategy->getInitialCapital());
    }

    public function getProfitPercent(): float
    {
        return $this->strategy->getCapital() * 100 / $this->strategy->getInitialCapital() - 100;
        //Calculator::calculate('$1 * 100 / $2 - 100', $this->strategy->getCapital(), $this->strategy->getInitialCapital());
    }

    public function getAvgProfit(string $profit, int $transactionCount): float
    {
        return $transactionCount > 0 ? $profit / $transactionCount : 0;
        //$transactionCount > 0 ? Calculator::calculate('$1 / $2', $profit, $transactionCount) : '0';
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

    protected function calcStatProfitableLosingTransactions(Position $position, float $profit): void
    {
        if ($profit > 0) {
            if ($position->getSide() === Side::Long) {
                $this->profitableTransactionsLong++;
            } else {
                $this->profitableTransactionsShort++;
            }
        } else {
            if ($position->getSide() === Side::Long) {
                $this->losingTransactionsLong++;
            } else {
                $this->losingTransactionsShort++;
            }
        }
    }

    protected function calcStatProfitLoss(float $profit, float $currentCapital): float
    {
        if ($profit > 0) {
            $this->profits += $profit; // Calculator::calculate('$1 + $2', $profits, $profit);
            $currentCapital += $profit; // Calculator::calculate('$1 + $2', $currentCapital, $profit);
        } else {
            $loss = abs($profit);
            $this->losses += $loss; // Calculator::calculate('$1 + $2', $losses, $loss);
            $currentCapital -= $loss; // Calculator::calculate('$1 - $2', $currentCapital, $loss);
        }
        return $currentCapital;
    }

    public function getTradeStats(array $tradeLog)
    {
        $currentCapital = $this->strategy->getInitialCapital();

        $this->capitalLog = [ $currentCapital ];
        $this->drawdownLog = [ 0.00 ];
        $this->benchmarkLog = [ $currentCapital ];

        $benchmarkQty = $this->benchmark !== null ? $this->calcStatBenchmarkQty($currentCapital) : null;
        $peakValue = $currentCapital;
        $troughValue = $currentCapital;

        $netProfit = $this->getProfit();
        $avgProfit = $this->getAvgProfit($netProfit, count($tradeLog));

        $totalOpenBars = 0;
        $maxDrawdownValue = 0;
        $maxDrawdownPercent = 0;

        $sharpeRatio = '0';
        $resultLog = [];

        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();
            $resultLog[] = $profit;

            $totalOpenBars += $position->getOpenBars();
            $this->calcStatMaxQty($position);

            $this->calcStatProfitableLosingTransactions($position, $profit);
            $currentCapital = $this->calcStatProfitLoss($profit, $currentCapital);

            $position->setPortfolioBalance($currentCapital);

            $this->capitalLog[] = $currentCapital;
            $this->drawdownLog[] = empty($position->getMaxDrawdownPercent()) ? 0 : 0 - round($position->getMaxDrawdownPercent(), 1);
            if ($this->benchmark !== null) {
                $this->benchmarkLog[] = $this->calcStatBenchmarkLogEntry($position, $benchmarkQty);
            }

            if ($position->getMaxDrawdownPercent() > $maxDrawdownPercent) {
                // todo: max drawdown pozycji to nie jest max drawdown całego testu!!!
                $maxDrawdownValue = $position->getMaxDrawdownValue();
                $maxDrawdownPercent = $position->getMaxDrawdownPercent();
            }
        }

        if (count($resultLog) > 2) {
            $stdDev = Calculator::stdDev($resultLog);
            $sharpeRatio = sqrt(count($tradeLog)) * $avgProfit / $stdDev;
            //Calculator::calculate('sqrt($1) * $2 / $3', count($tradeLog), $avgProfit, $stdDev);
        }

        $profitableTransactions = $this->profitableTransactionsLong + $this->profitableTransactionsShort;
        $losingTransactions = $this->losingTransactionsLong + $this->losingTransactionsShort;
        $params = [
            'capital_log' => $this->capitalLog,
            'drawdown_log' => $this->drawdownLog,
            'net_profit' => $netProfit,
            'avg_profit' => $avgProfit,
            'avg_profit_longs' => '0',
            'avg_profit_shorts' => '0',
            'net_profit_longs' => $this->strategy->getNetProfitLongs(),
            'net_profit_shorts' => $this->strategy->getNetProfitShorts(),
            'gross_profit_longs' => $this->strategy->getGrossProfitLongs(),
            'gross_profit_shorts' => $this->strategy->getGrossProfitShorts(),
            'gross_loss_longs' => $this->strategy->getGrossLossLongs(),
            'gross_loss_shorts' => $this->strategy->getGrossLossShorts(),
            'profitable_transactions' => count($tradeLog) > 0 ? $profitableTransactions * 100 / count($tradeLog) -1 : 0,
            'profit_factor' => $this->losses > 0 ? $this->profits / $this->losses : 0.00,
            'profit_factor_longs' => $this->strategy->getGrossLossLongs() > 0 ? $this->strategy->getGrossProfitLongs() / $this->strategy->getGrossLossLongs() : 0,
            'profit_factor_shorts' => $this->strategy->getGrossLossShorts() > 0 ? $this->strategy->getGrossProfitShorts() / $this->strategy->getGrossLossShorts() : 0,
            'sharpe_ratio' => $sharpeRatio,
            'max_quantity_longs' => $this->maxQuantityLongs,
            'max_quantity_shorts' => $this->maxQuantityShorts,
            'max_drawdown_value' => $maxDrawdownValue,
            'max_drawdown_percent' => $maxDrawdownPercent,
            'avg_bars' => $totalOpenBars / count($tradeLog),
            'peak_value' => $peakValue,
            'trough_value' => $troughValue,
            'profitable_transactions_long_count' => $this->profitableTransactionsLong,
            'profitable_transactions_short_count' => $this->profitableTransactionsShort,
            'losing_transactions_long_count' => $this->losingTransactionsLong,
            'losing_transactions_short_count' => $this->losingTransactionsShort,
            'avg_profitable_transaction' => $profitableTransactions > 0 ? ($this->strategy->getGrossProfitLongs() + $this->strategy->getGrossProfitShorts()) / $profitableTransactions : 0,
            'avg_profitable_transaction_longs' => $this->profitableTransactionsLong > 0 ? $this->strategy->getGrossProfitLongs() / (float)$this->profitableTransactionsLong : 0,
            'avg_profitable_transaction_shorts' => $this->profitableTransactionsShort > 0 ? $this->strategy->getGrossProfitShorts() / (float)$this->profitableTransactionsShort : 0,
            'avg_losing_transaction' => $losingTransactions > 0 ? ($this->strategy->getGrossLossLongs() + $this->strategy->getGrossLossShorts()) / $losingTransactions : 0,
            'avg_losing_transaction_longs' => $this->losingTransactionsLong > 0 ? $this->strategy->getGrossLossLongs() / (float)$this->losingTransactionsLong : 0,
            'avg_losing_transaction_shorts' => $this->losingTransactionsShort > 0 ? $this->strategy->getGrossLossShorts() / (float)$this->losingTransactionsShort : 0,
        ];
        if ($this->benchmark !== null) {
            $params['benchmark_log'] = $this->benchmarkLog;
        }
        return $params;
    }

    /**
     * @throws ReflectionException
     * @throws LoaderException
     * @throws BacktesterException
     * @throws StrategyException
     */
    public function runBacktest(Assets $assets, DateTime $startTime, ?DateTime $endTime = null): void
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
        // TODO: check if the parent onOpen/onClose is called in the child

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