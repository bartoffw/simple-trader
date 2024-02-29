<?php

namespace SimpleTrader;

use SimpleTrader\BaseStrategy;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Loaders\LoaderInterface;
use SimpleTrader\Loggers\Level;

class TestStrategy extends BaseStrategy
{
    public function onOpen(Assets $assets, DateTime $dateTime)
    {
        /** @var LoaderInterface $asset */
        foreach ($assets->getAssets() as $asset) {
            $this->logger?->log(Level::Debug, '[OPEN] Asset: ' . $asset->getTicker() . ', date: ' . $dateTime->getDateTime() . ', ' . $asset->getCurrentValues($dateTime)->toString());
        }
    }

    public function onClose(Assets $assets, DateTime $dateTime)
    {
        /** @var LoaderInterface $asset */
        foreach ($assets->getAssets() as $asset) {
            $this->logger?->log(Level::Debug, '[CLOSE] Asset: ' . $asset->getTicker() . ', date: ' . $dateTime->getDateTime() . ', ' . $asset->getCurrentValues($dateTime)->toString());
        }
    }
}