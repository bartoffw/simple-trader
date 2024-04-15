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
    protected ?DataFrame $benchmark = null;
    protected DateTime $backtestStartTime;
    protected ?DateTime $backtestEndTime;
    protected string $backtestStarted;
    protected string $backtestFinished;
    protected string $lastBacktestTime;

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

    public function setBenchmark(DataFrame $asset): void
    {
        $this->benchmark = $asset;
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
        return $this->benchmark?->getTicker();
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

    public function getTradeStats(array $tradeLog)
    {
        $currentCapital = $this->strategy->getInitialCapital();
        $capitalLog = [
            $currentCapital
        ];
        $drawdownLog = [
            0.00
        ];
        $benchmarkLog = [
            $currentCapital
        ];
//        if ($this->benchmark !== null) {
//            $benchmarkLoaded = $this->loader->loadAsset($this->benchmark, $this->strategy->getStartDateForCalculations(), $this->strategy->getStartDate(), Event::OnClose);
//            $benchmarkValue = $benchmarkLoaded->getCurrentValue();
//            $benchmarkQty = $benchmarkValue ? $currentCapital / $benchmarkValue : 0;
//        }
        $peakValue = $currentCapital;
        $troughValue = $currentCapital;

        $netProfit = $this->getProfit();
        $profits = 0;
        $losses = '0';
        $avgProfit = $this->getAvgProfit($netProfit, count($tradeLog));

        $totalOpenBars = 0;
        $profitableTransactionsLong = 0;
        $profitableTransactionsShort = 0;
        $losingTransactionsLong = 0;
        $losingTransactionsShort = 0;
        $maxDrawdownValue = 0;
        $maxDrawdownPercent = 0;
        $maxQuantityLongs = 0;
        $maxQuantityShorts = 0;

        $sharpeRatio = '0';
        $resultLog = [];

        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();
            $resultLog[] = $profit;

            $totalOpenBars += $position->getOpenBars();
            $quantity = $position->getQuantity();
            if ($position->getSide() === Side::Long && $quantity > $maxQuantityLongs /*Calculator::compare($quantity, $maxQuantityLongs) > 0*/) {
                $maxQuantityLongs = $position->getQuantity();
            }
            if ($position->getSide() === Side::Short && $quantity > $maxQuantityShorts /*Calculator::compare($quantity, $maxQuantityShorts) > 0*/) {
                $maxQuantityShorts = $position->getQuantity();
            }

            if ($profit > 0) {
                if ($position->getSide() === Side::Long) {
                    $profitableTransactionsLong++;
                } else {
                    $profitableTransactionsShort++;
                }
                $profits += $profit; // Calculator::calculate('$1 + $2', $profits, $profit);
                $currentCapital += $profit; // Calculator::calculate('$1 + $2', $currentCapital, $profit);
            } else {
                if ($position->getSide() === Side::Long) {
                    $losingTransactionsLong++;
                } else {
                    $losingTransactionsShort++;
                }
                $loss = abs($profit);
                $losses += $loss; // Calculator::calculate('$1 + $2', $losses, $loss);
                $currentCapital -= $loss; // Calculator::calculate('$1 - $2', $currentCapital, $loss);
            }
            $position->setPortfolioBalance($currentCapital);

            $capitalLog[] = $currentCapital;
            $drawdownLog[] = empty($position->getMaxDrawdownPercent()) ? 0 : 0 - round($position->getMaxDrawdownPercent(), 1);
//            if ($this->benchmark !== null) {
//                $benchmarkLoaded = $this->loader->loadAsset($this->benchmark, $this->strategy->getStartDate(), $position->getCloseTime(), Event::OnClose);
//                $benchmarkValue = $benchmarkLoaded->getCurrentValue();
//                $benchmarkLog[] = round($benchmarkValue * $benchmarkQty, 2);
////                echo "{$position->getCloseTime()}: $benchmarkValue\n";
//            }

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

        $profitableTransactions = $profitableTransactionsLong + $profitableTransactionsShort;
        $losingTransactions = $losingTransactionsLong + $losingTransactionsShort;
        $params = [
            'capital_log' => $capitalLog,
            'drawdown_log' => $drawdownLog,
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
            'profit_factor' => $losses > 0 ? $profits / $losses : 0,
            'profit_factor_longs' => $this->strategy->getGrossLossLongs() > 0 ? $this->strategy->getGrossProfitLongs() / $this->strategy->getGrossLossLongs() : 0,
            'profit_factor_shorts' => $this->strategy->getGrossLossShorts() > 0 ? $this->strategy->getGrossProfitShorts() / $this->strategy->getGrossLossShorts() : 0,
            'sharpe_ratio' => $sharpeRatio,
            'max_quantity_longs' => $maxQuantityLongs,
            'max_quantity_shorts' => $maxQuantityShorts,
            'max_drawdown_value' => $maxDrawdownValue,
            'max_drawdown_percent' => $maxDrawdownPercent,
            'avg_bars' => $totalOpenBars / count($tradeLog),
            'peak_value' => $peakValue,
            'trough_value' => $troughValue,
            'profitable_transactions_long_count' => $profitableTransactionsLong,
            'profitable_transactions_short_count' => $profitableTransactionsShort,
            'losing_transactions_long_count' => $losingTransactionsLong,
            'losing_transactions_short_count' => $losingTransactionsShort,
            'avg_profitable_transaction' => abs($profitableTransactions) > 0.00001 ? ($this->strategy->getGrossProfitLongs() + $this->strategy->getGrossProfitShorts()) / $profitableTransactions : 0,
            'avg_profitable_transaction_longs' => abs($profitableTransactionsLong) > 0.00001 ? $this->strategy->getGrossProfitLongs() / $profitableTransactionsLong : 0,
            'avg_profitable_transaction_shorts' => abs($profitableTransactionsShort) > 0.00001 ? $this->strategy->getGrossProfitShorts() / $profitableTransactionsShort : 0,
            'avg_losing_transaction' => abs($losingTransactions) > 0.00001 ? ($this->strategy->getGrossLossLongs() + $this->strategy->getGrossLossShorts()) / $losingTransactions : 0,
            'avg_losing_transaction_longs' => abs($losingTransactionsLong) > 0.00001 ? $this->strategy->getGrossLossLongs() / $losingTransactionsLong : 0,
            'avg_losing_transaction_shorts' => abs($losingTransactionsShort) > 0.00001 ? $this->strategy->getGrossLossShorts() / $losingTransactionsShort : 0,
        ];
        if ($this->benchmark !== null) {
            $params['benchmark_log'] = $benchmarkLog;
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
        $currentAssets = null;
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