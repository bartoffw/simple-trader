<?php

namespace SimpleTrader\Loaders;

use Carbon\Carbon;
use SimpleTrader\Event;
use SimpleTrader\Helpers\Ohlc;

class BaseLoader implements LoaderInterface
{
    protected bool $isLoaded = false;


//    public function __call(string $name, array $arguments)
//    {
//
//    }

    public function getTicker():string
    {
        return '';
    }

    public function getFromDate():?Carbon
    {
        return null;
    }

    public function isLoaded():bool
    {
        return $this->isLoaded;
    }

    public function loadData(?Carbon $fromDate = null): bool
    {
        return false;
    }

    public function getData(?string $column = null): array
    {
        return [];
    }

    public static function fromLoaderLimited(LoaderInterface $loader, Carbon $limitToDate, Event $event): static
    {
        return new static();
    }

    public function getDateField(): string
    {
        return '';
    }

    public function getCurrentValues(Carbon $dateTime): Ohlc
    {
        return new Ohlc($dateTime, '0', '0', '0', '0');
    }
}