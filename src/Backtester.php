<?php

namespace SimpleTrader;

use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Loggers\LoggerInterface;

class Backtester
{
    protected ?LoggerInterface $logger = null;
    protected BaseStrategy $strategy;
    protected Assets $assets;

    public function __construct(protected Resolution $resolution)
    {
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function setStrategy(BaseStrategy $strategy)
    {
        $this->strategy = $strategy;
    }

    public function runBacktest(Assets $assets, DateTime $startTime, ?DateTime $endTime = null)
    {
        if (!isset($this->strategy)) {
            throw new LoaderException('Strategy is not set');
        }
        if ($assets->isEmpty()) {
            throw new LoaderException('No assets defined');
        }
        $this->strategy->setLogger($this->logger);

        $this->logger?->log(Level::Debug, 'Starting the backtest');
        while ($endTime === null || $startTime->getCurrentDateTime() <= $endTime->getDateTime()) {
            $this->logger?->log(Level::Debug, 'Backtest day: ' . $startTime->getCurrentDateTime());
            $currentDateTime = new DateTime($startTime->getCurrentDateTime());

            $this->strategy->onOpen($assets->getAssetsForDates($startTime, $currentDateTime, Event::OnOpen), $currentDateTime);
            $this->strategy->onClose($assets->getAssetsForDates($startTime, $currentDateTime, Event::OnClose), $currentDateTime);

            $startTime->increaseByStep($this->resolution);
            // TODO
        }
    }
}