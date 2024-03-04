<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\Event;

class Asset
{
    public function __construct(protected string $ticker, protected array $data = [], protected ?Event $event = null)
    {

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
}