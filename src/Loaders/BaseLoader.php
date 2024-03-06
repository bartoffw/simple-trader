<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\Event;
use SimpleTrader\Helpers\DateTime;
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

    public function getFromDate():?DateTime
    {
        return null;
    }

    public function isLoaded():bool
    {
        return $this->isLoaded;
    }

    public function loadData(?DateTime $fromDate = null): bool
    {
        return false;
    }

    public function getData(?string $column = null): array
    {
        return [];
    }

    public static function fromLoaderLimited(LoaderInterface $loader, DateTime $limitToDate, Event $event): static
    {
        return new static();
    }

    public function getDateField(): string
    {
        return '';
    }

    public function getCurrentValues(DateTime $dateTime): Ohlc
    {
        return new Ohlc($dateTime, '0', '0', '0', '0');
    }
}