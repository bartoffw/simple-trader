<?php

namespace SimpleTrader;

use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Loggers\LoggerInterface;

class BaseStrategy
{
    protected ?LoggerInterface $logger = null;
    protected array $openTrades = [];


    public function __construct()
    {

    }

    public function setLogger(LoggerInterface $logger, bool $override = false)
    {
        if ($override || $this->logger === null) {
            $this->logger = $logger;
        }
    }

    public function onOpen(Assets $assets, DateTime $dateTime)
    {

    }

    public function onClose(Assets $assets, DateTime $dateTime)
    {

    }

    public function buy()
    {

    }

    public function sell()
    {

    }

    public function closeAll()
    {

    }
}