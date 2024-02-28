<?php

namespace SimpleTrader;

use SimpleTrader\Resolution;
use SimpleTrader\Exceptions\LoaderException;

class Backtester
{
    protected array $loggers;
    protected BaseStrategy $strategy;
    protected Assets $assets;

    public function __construct(protected Resolution $resolution)
    {
    }

    public function setLoggers(array $loggers)
    {
        $this->loggers = $loggers;
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

        while ($endTime === null || $startTime->getCurrentDateTime() <= $endTime->getDateTime()) {
            $currentDateTime = new DateTime($startTime->getCurrentDateTime());
            $this->strategy->onOpen($assets->getLimitedToDate($currentDateTime, Event::OnOpen), $currentDateTime);
            $this->strategy->onClose($assets->getLimitedToDate($currentDateTime, Event::OnClose), $currentDateTime);

            $startTime->increaseByStep($this->resolution);
            // TODO
        }
    }
}