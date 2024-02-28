<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\DateTime;
use SimpleTrader\Event;

interface LoaderInterface
{
    public function getTicker():string;
    public function getFromDate():?DateTime;
    public function loadData(?DateTime $fromDate = null):bool;
    public function getData(?string $column = null):array;
    public function isLoaded():bool;
    public function limitToDate(DateTime $dateTime, Event $event):LoaderInterface;
}