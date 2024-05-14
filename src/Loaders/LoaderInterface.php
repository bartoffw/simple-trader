<?php

namespace SimpleTrader\Loaders;

use Carbon\Carbon;
use SimpleTrader\Event;
use SimpleTrader\Helpers\Ohlc;

interface LoaderInterface
{
    public static function fromLoaderLimited(LoaderInterface $loader, Carbon $limitToDate, Event $event): static;
    public function getTicker():string;
    public function getDateField(): string;
    public function getFromDate():?Carbon;
    public function loadData(?Carbon $fromDate = null):bool;
    public function getData(?string $column = null):array;
    public function getCurrentValues(Carbon $dateTime): Ohlc;
    public function isLoaded():bool;
}