<?php

namespace SimpleTrader;

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
use SimpleTrader\Loggers\Level;
use SimpleTrader\Loggers\LoggerInterface;

class Backtester
{
    protected ?LoggerInterface $logger = null;
    protected BaseStrategy $strategy;
    protected Assets $assets;
    protected DateTime $backtestStartTime;
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

    public function getStrategyName(): string
    {
        return $this->strategy->getStrategyName();
    }

    public function getLastBacktestTime(): string
    {
        return $this->lastBacktestTime;
    }

    public function getTradeLog(): array
    {
        return $this->strategy->getTradeLog();
    }

    public function getProfit(): string
    {
        return Calculator::calculate('$1 - $2', $this->strategy->getCapital(), $this->strategy->getInitialCapital());
    }

    public function getProfitPercent(): string
    {
        return Calculator::calculate('$1 * 100 / $2 - 100', $this->strategy->getCapital(), $this->strategy->getInitialCapital());
    }

    public function getAvgProfit(string $profit, int $transactionCount): string
    {
        return $transactionCount > 0 ? Calculator::calculate('$1 / $2', $profit, $transactionCount) : '0';
    }

    public function getTradeStats(array $tradeLog)
    {
        $currentCapital = $this->strategy->getInitialCapital();
        $capitalLog = [
            $currentCapital
        ];
        $peakValue = $currentCapital;
        $troughValue = $currentCapital;
        $currentTroughValue = $currentCapital;

        $profits = '0';
        $losses = '0';
        $grossProfitLongs = '0';
        $grossProfitShorts = '0';
        $grossLossLongs = '0';
        $grossLossShorts = '0';

        $totalOpenBars = 0;
        $profitableTransactions = 0;
        $losingTransactions = 0;
        $maxDrawdownValue = '0';
        $maxDrawdownPercent = '0';
        $maxQuantityLongs = '0';
        $maxQuantityShorts = '0';

        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();
            $totalOpenBars += $position->getOpenBars();
            $quantity = $position->getQuantity();
            if ($position->getSide() === Side::Long && Calculator::compare($quantity, $maxQuantityLongs) > 0) {
                $maxQuantityLongs = $position->getQuantity();
            }
            if ($position->getSide() === Side::Short && Calculator::compare($quantity, $maxQuantityShorts) > 0) {
                $maxQuantityShorts = $position->getQuantity();
            }

            if ($profit > 0) {
                $profitableTransactions++;
                $profits = Calculator::calculate('$1 + $2', $profits, $profit);
                $currentCapital = Calculator::calculate('$1 + $2', $currentCapital, $profit);

                if ($position->getSide() === Side::Long) {
                    $grossProfitLongs = Calculator::calculate('$1 + $2', $grossProfitLongs, $profit);
                }
                if ($position->getSide() === Side::Short) {
                    $grossProfitShorts = Calculator::calculate('$1 + $2', $grossProfitShorts, $profit);
                }

                if (Calculator::compare($currentCapital, $peakValue) > 0) {
                    $peakValue = $currentCapital;
                }
            } else {
                $losingTransactions++;
                $loss = trim($profit, '-');
                $losses = Calculator::calculate('$1 + $2', $losses, $loss);
                $currentCapital = Calculator::calculate('$1 - $2', $currentCapital, trim($profit, '-'));

                if ($position->getSide() === Side::Long) {
                    $grossLossLongs = Calculator::calculate('$1 + $2', $grossLossLongs, $loss);
                }
                if ($position->getSide() === Side::Short) {
                    $grossLossShorts = Calculator::calculate('$1 + $2', $grossLossShorts, $loss);
                }
            }
            $capitalLog[] = $currentCapital;
            $position->setPortfolioBalance($currentCapital);
            if (Calculator::compare($currentCapital, $peakValue) < 0) {
                $currentTroughValue = $currentCapital;
                $currentDrawdownValue = Calculator::calculate('$1 - $2', $peakValue, $currentTroughValue);
                $currentDrawdownPercent = Calculator::calculate('$1 * 100 / $2', $currentDrawdownValue, $peakValue);
            } else {
                $currentDrawdownValue = '0';
                $currentDrawdownPercent = '0';
            }
            $position->setPortfolioDrawdown($currentDrawdownValue, $currentDrawdownPercent);
            if (Calculator::compare($currentDrawdownPercent, $maxDrawdownPercent) > 0) {
                $maxDrawdownValue = $currentDrawdownValue;
                $maxDrawdownPercent = $currentDrawdownPercent;
                $troughValue = $currentTroughValue;
            }
        }
        return [
            'capital_log' => $capitalLog,
            'net_profit' => $this->getProfit(),
            'gross_profit_longs' => $grossProfitLongs,
            'gross_profit_shorts' => $grossProfitShorts,
            'gross_loss_longs' => $grossLossLongs,
            'gross_loss_shorts' => $grossLossShorts,
            'profitable_transactions' => count($tradeLog) > 0 ? Calculator::calculate('$1 * 100 / $2 - 1', $profitableTransactions, count($tradeLog)) : '0',
            'profit_factor' => $losses > 0 ? Calculator::calculate('$1 / $2', $profits, $losses) : '0',
            'max_quantity_longs' => $maxQuantityLongs,
            'max_quantity_shorts' => $maxQuantityShorts,
            'max_drawdown_value' => $maxDrawdownValue,
            'max_drawdown_percent' => $maxDrawdownPercent,
            'avg_bars' => Calculator::calculate('$1 / $2', $totalOpenBars, count($tradeLog)),
            'peak_value' => $peakValue,
            'trough_value' => $troughValue,
            'avg_profitable_transaction' => Calculator::compare($losingTransactions, '0') > 0 ? Calculator::calculate('$1 / $2', $profits, $profitableTransactions) : '0',
            'avg_losing_transaction' => Calculator::compare($losingTransactions, '0') > 0 ? Calculator::calculate('$1 / $2', $losses, $losingTransactions) : '0',
            'sharpe' => '?'
        ];
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
        $this->strategy->setLogger($this->logger);
        $this->backtestStartTime = $startTime;
        $this->backtestStarted = microtime(true);

        $onOpenExists = (new ReflectionMethod($this->strategy, 'onOpen'))->getDeclaringClass()->getName() !== BaseStrategy::class;
        $onCloseExists = (new ReflectionMethod($this->strategy, 'onClose'))->getDeclaringClass()->getName() !== BaseStrategy::class;

        $this->logger?->log(Level::Debug, 'Starting the backtest');
        while ($endTime === null || $startTime->getCurrentDateTime() <= $endTime->getDateTime()) {
            $this->logger?->log(Level::Debug, 'Backtest day: ' . $startTime->getCurrentDateTime());
            $currentDateTime = new DateTime($startTime->getCurrentDateTime());

            if ($onOpenExists) {
                $this->strategy->onOpen($assets->getAssetsForDates($startTime, $currentDateTime, Event::OnOpen), $currentDateTime);
            }
            if ($onCloseExists) {
                $this->strategy->onClose($assets->getAssetsForDates($startTime, $currentDateTime, Event::OnClose), $currentDateTime);
            }
            /** @var Position $position */
            foreach ($this->strategy->getOpenTrades() as $position) {
                $position->incrementOpenBars();
            }
            $startTime->increaseByStep($this->resolution);
            // TODO
        }
        $currentDateTime = new DateTime($startTime->getCurrentDateTime());
        $this->strategy->onStrategyEnd($assets->getAssetsForDates($startTime, $currentDateTime, Event::OnClose), $currentDateTime);

        $this->backtestFinished = microtime(true);
        $this->lastBacktestTime = $this->backtestFinished - $this->backtestStarted;
    }
}