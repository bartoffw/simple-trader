<?php

namespace SimpleTrader\Helpers;

use Carbon\Carbon;

class Ohlc
{
    public function __construct(protected Carbon $dateTime, protected string $open, protected string $high,
                                protected string $low, protected string $close, protected ?int $volume = 0) {}

    public function getDateTime(): Carbon
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

    public function toArray($round = 2): array
    {
        return [
            'date' => $this->dateTime->toDateString(),
            'open' => (float) ($round ? round($this->open, $round) : $this->open),
            'high' => (float) ($round ? round($this->high, $round) : $this->high),
            'low' => (float) ($round ? round($this->low, $round) : $this->low),
            'close' => (float) ($round ? round($this->close, $round) : $this->close),
            'volume' => (float) ($round ? round($this->volume, $round) : $this->volume),
        ];
    }

    public function __toString(): string
    {
        return "[" . $this->dateTime->toDateString() . "] O: {$this->open}; H: {$this->high}; L: {$this->low}; C: {$this->close}; V: {$this->volume}";
    }
}