<?php

namespace SimpleTrader;

use SimpleTrader\Resolution;
use SimpleTrader\Exceptions\LoaderException;

class Backtester
{
    protected array $loggers;
    protected BaseStrategy $strategy;
    protected Assets $assets;

    public function __construct(protected Resolution $resolution, protected DateTime $startTime, protected ?DateTime $endTime = null)
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

    public function runBacktest(DateTime $startTime, ?DateTime $endTime = null)
    {
        if (!isset($this->strategy)) {
            throw new LoaderException('Strategy is not set');
        }
        if (!isset($this->assets) || $this->assets->isEmpty()) {
            throw new LoaderException('No assets defined');
        }

        while ($endTime === null || $startTime->getCurrentDateTime() <= $endTime->getDateTime()) {
            // TODO
        }
    }
}