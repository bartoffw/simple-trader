<?php

namespace SimpleTrader\Helpers;

use LupeCode\phpTraderNative\TraderFriendly;
use SimpleTrader\Event;

class Asset
{
    protected bool $isLoaded = false;
    protected array $opens = [];
    protected array $highs = [];
    protected array $lows = [];
    protected array $closes = [];


    public function __construct(protected string $ticker, protected array $data = [], protected ?Event $event = null)
    {
        if (!empty($this->data)) {
            $this->isLoaded = true;
        }
        foreach ($this->data as $element) {
            $this->opens[] = $element['open'];
            $this->highs[] = $element['high'];
            $this->lows[] = $element['low'];
            $this->closes[] = $element['close'];
        }
    }

    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getRawData(): array
    {
        return $this->data;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function hasValues(int $minLength): bool
    {
        return $this->isLoaded() && count($this->data) >= $minLength;
    }

    public function getLatestValues(): ?Ohlc
    {
        if ($this->isLoaded()) {
            $dateTime = new DateTime(array_key_last($this->data));
            $element = end($this->data);
            return $this->event === Event::OnOpen ?
                new Ohlc($dateTime, $element['open'], $element['open'], $element['open'], $element['open']) :
                new Ohlc($dateTime, $element['open'], $element['high'], $element['low'], $element['close'], $element['volume']);
        }
        return null;
    }

    public function getLastDateTime(): ?string
    {
        if ($this->isLoaded()) {
            return array_key_last($this->data);
        }
        return null;
    }

    public function getCurrentValue(): ?string
    {
        if ($this->isLoaded() && $this->event !== null) {
            $element = end($this->data);
            return $this->event == Event::OnOpen ? $element['open'] : $element['close'];
        }
        return null;
    }

    public function getOpens(): array
    {
        return $this->opens;
    }

    public function getHighs(): array
    {
        return $this->highs;
    }

    public function getLows(): array
    {
        return $this->lows;
    }

    public function getCloses(): array
    {
        return $this->closes;
    }
}