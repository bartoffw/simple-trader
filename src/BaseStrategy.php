<?php

namespace SimpleTrader;

use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Loggers\LoggerInterface;

class BaseStrategy
{
    protected ?LoggerInterface $logger = null;
    protected array $openTrades = [];
    protected ?string $capital = null;
    protected int $precision = 2;


    public function __construct()
    {

    }

    public function setLogger(LoggerInterface $logger, bool $override = false): void
    {
        if ($override || $this->logger === null) {
            $this->logger = $logger;
        }
    }

    public function setCapital(string $capital, int $precision = 2): void
    {
        $this->precision = $precision;
        $this->capital = $capital;
        bcscale($precision);
    }

    public function getCapital($formatted = false): ?string
    {
        return $formatted ? number_format($this->capital, $this->precision) : $this->capital;
    }

    public function onOpen(Assets $assets, DateTime $dateTime): void
    {

    }

    public function onClose(Assets $assets, DateTime $dateTime): void
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