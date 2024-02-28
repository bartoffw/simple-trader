<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\DateTime;
use SimpleTrader\Event;

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

    public function limitToDate(DateTime $dateTime, Event $event): LoaderInterface
    {
        $this->isLoaded = true;
        return $this;
    }
}