<?php

namespace SimpleTrader\Helpers;

class Ohlc
{
    public function __construct(protected DateTime $dateTime, protected string $open, protected string $high,
                                protected string $low, protected string $close, protected ?int $volume = 0) {}

    public function getDateTime(): DateTime
    {
        return $this->dateTime;
    }

    public function getOpen(): string
    {
        return $this->open;
    }

    public function getHigh(): string
    {
        return $this->high;
    }

    public function getLow(): string
    {
        return $this->low;
    }

    public function getClose(): string
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