<?php

namespace SimpleTrader\Loaders;

interface SourceInterface
{
    public function getQuotes(string $symbol, string $exchange, string $interval = '1D', int $barCount = 10): array;
}