<?php

namespace SimpleTrader;

use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Loaders\LoaderInterface;

class Assets
{
    protected array $assetList = [];


    public function __construct()
    {

    }

    public function addAsset(LoaderInterface $loader, DateTime $fromDate, $replace = false):void
    {
        $ticker = $loader->getTicker();
        if (isset($this->assetList[$ticker]) && !$replace) {
            throw new LoaderException('This asset is already loaded: ' . $ticker);
        }
        if (!$loader->isLoaded()) {
            $loader->loadData($fromDate);
        }
        $this->assetList[$ticker] = $loader;
    }

    public function isEmpty():bool
    {
        return empty($this->assetList);
    }

    public function getLimitedToDate(DateTime $dateTime, Event $event):Assets
    {
        $limitedAssets = new Assets();
        /** @var LoaderInterface $asset */
        foreach ($this->assetList as $asset) {
            $limitedAsset = $asset->limitToDate($dateTime, $event);
            if ($event === Event::OnOpen) {
                // TODO: need to overwrite the last day to show all open on OHLC
            }
            $limitedAssets->addAsset($limitedAsset, $asset->getFromDate());
        }
        return $limitedAssets;
    }
}