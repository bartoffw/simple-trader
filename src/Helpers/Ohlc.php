<?php

namespace SimpleTrader\Helpers;

class Ohlc
{
    public function __construct(protected DateTime $dateTime, protected float $open, protected float $high,
                                protected float $low, protected float $close, protected ?int $volume = 0) {}

    public function getDateTime(): DateTime
    {
        return $this->dateTime;
    }

    public function getOpen(): float
    {
        return $this->open;
    }

    public function getHigh(): float
    {
        return $this->high;
    }

    public function getLow(): float
    {
        return $this->low;
    }

    public function getClose(): float
    {
        return $this->close;
    }

    public function getVolume(): int
    {
        return $this->volume;
    }

    public function toString(): string
    {
        return "O: {$this->open}; H: {$this->high}; L: {$this->low}; C: {$this->close}";
    }
}