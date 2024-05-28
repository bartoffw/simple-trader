<?php

namespace SimpleTrader;

use Carbon\Carbon;
use SimpleTrader\Loggers\Level;

class TestStrategy extends BaseStrategy
{
    public function onOpen(Assets $assets, Carbon $dateTime): void
    {
        /** @var Asset $asset */
        foreach ($assets->getAssets() as $asset) {
            $this->logger?->log(Level::Debug, '[OPEN] Asset: ' . $asset->getTicker() . ', date: ' . $dateTime->toDateString() . ', ' . $asset->getLatestValues()?->toString());
        }
    }

    public function onClose(Assets $assets, Carbon $dateTime): void
    {
        /** @var Asset $asset */
        foreach ($assets->getAssets() as $asset) {
            $this->logger?->log(Level::Debug, '[CLOSE] ' .
                'Asset: ' . $asset->getTicker() . ', ' .
                'date: ' . $dateTime->toDateString() . ', ' .
                $asset->getLatestValues()?->toString() . ', ' .
                'capital: ' . $this->getCapital(true)
            );
        }
    }
}