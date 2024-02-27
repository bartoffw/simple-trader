<?php

namespace SimpleTrader\Loaders;

use SimpleTrader\DateTime;

interface LoaderInterface
{
    public function loadData(?DateTime $fromDate = null):bool;
    public function getData(?string $column = null):array;
}