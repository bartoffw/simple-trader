<?php

namespace SimpleTrader;

use ReflectionException;
use ReflectionMethod;
use SimpleTrader\Exceptions\BacktesterException;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\Calculator;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Position;
use SimpleTrader\Helpers\Resolution;
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
        $lastPeak = $currentCapital;
        $lastThrough = $currentCapital;

        $profitableTransactions = 0;
        $profits = '0';
        $losses = '0';
        $maxDrawdown = '0';

        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();
            $capitalLog[] = $currentCapital;

            if ($profit > 0) {
                $profitableTransactions++;
                $profits = Calculator::calculate('$1 + $2', $profits, $profit);
                $currentCapital = Calculator::calculate('$1 + $2', $currentCapital, $profit);
                if (Calculator::compare($currentCapital, $lastPeak) > 0) {
                    $lastPeak = $currentCapital;
                }
            } else {
                $losses = Calculator::calculate('$1 + $2', $losses, trim($profit, '-'));
                $currentCapital = Calculator::calculate('$1 - $2', $currentCapital, trim($profit, '-'));
                if (Calculator::compare($currentCapital, $lastThrough) < 0) {
                    $lastThrough = $currentCapital;
                }
            }
        }
        return [
            'capital_log' => $capitalLog,
            'profitable_transactions' => count($tradeLog) > 0 ? Calculator::calculate('$1 * 100 / $2 - 1', $profitableTransactions, count($tradeLog)) : '0',
            'profit_factor' => $losses > 0 ? Calculator::calculate('$1 / $2', $profits, $losses) : '0',
            'max_drawdown' => $maxDrawdown,
            'avg_bars' => '?'
        ];
    }

    /**
     * @throws ReflectionException
     * @throws LoaderException
     * @throws BacktesterException
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
            $startTime->increaseByStep($this->resolution);
            // TODO
        }
        $currentDateTime = new DateTime($startTime->getCurrentDateTime());
        $this->strategy->onStrategyEnd($assets->getAssetsForDates($startTime, $currentDateTime, Event::OnClose), $currentDateTime);

        $this->backtestFinished = microtime(true);
        $this->lastBacktestTime = $this->backtestFinished - $this->backtestStarted;
    }
}