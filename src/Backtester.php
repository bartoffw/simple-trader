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

    public function getCapitalLog(): array
    {
        $currentCapital = $this->strategy->getInitialCapital();
        $capitalLog = [
            $currentCapital
        ];
        $tradeLog = $this->strategy->getTradeLog();
        /** @var Position $position */
        foreach ($tradeLog as $position) {
            $profit = $position->getProfitAmount();
            $currentCapital = $profit > 0 ?
                Calculator::calculate('$1 + $2', $currentCapital, $profit) :
                Calculator::calculate('$1 - $2', $currentCapital, trim($profit, '-'));
            $capitalLog[] = $currentCapital;
        }
        return $capitalLog;
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