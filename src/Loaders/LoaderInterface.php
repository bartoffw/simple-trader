<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\Event;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Ohlc;

interface LoaderInterface
{
    public static function fromLoaderLimited(LoaderInterface $loader, DateTime $limitToDate, Event $event): static;
    public function getTicker():string;
    public function getDateField(): string;
    public function getFromDate():?DateTime;
    public function loadData(?DateTime $fromDate = null):bool;
    public function getData(?string $column = null):array;
    public function getCurrentValues(DateTime $dateTime): Ohlc;
    public function isLoaded():bool;
}