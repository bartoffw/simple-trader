<?php

namespace SimpleTrader;

use SimpleTrader\Helpers\Asset;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Loaders\LoaderInterface;
use SimpleTrader\Loggers\Level;

class TestStrategy extends BaseStrategy
{
//    public function onOpen(Assets $assets, DateTime $dateTime): void
//    {
//        /** @var Asset $asset */
//        foreach ($assets->getAssets() as $asset) {
//            $this->logger?->log(Level::Debug, '[OPEN] Asset: ' . $asset->getTicker() . ', date: ' . $dateTime->getDateTime() . ', ' . $asset->getLatestValues()?->toString());
//        }
//    }

    public function onClose(Assets $assets, DateTime $dateTime): void
    {
        /** @var Asset $asset */
        foreach ($assets->getAssets() as $asset) {
            $this->logger?->log(Level::Debug, '[CLOSE] ' .
                'Asset: ' . $asset->getTicker() . ', ' .
                'date: ' . $dateTime->getDateTime() . ', ' .
                $asset->getLatestValues()?->toString() . ', ' .
                'capital: ' . $this->getCapital(true)
            );
        }
    }
}