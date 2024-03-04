<?php

namespace SimpleTrader;

use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Asset;
use SimpleTrader\Loaders\SQLite;

class Assets
{
    protected array $assetList = [];
    protected SQLite $loader;


    public function __construct()
    {

    }

    public function setLoader(SQLite $loader): void
    {
        $this->loader = $loader;
    }

    public function addAsset(Asset $asset, $replace = false): void
    {
        $ticker = $asset->getTicker();
        if (isset($this->assetList[$ticker]) && !$replace) {
            throw new LoaderException('This asset is already loaded: ' . $ticker);
        }
        $this->assetList[$ticker] = $asset;
    }

    public function getAssets(): array
    {
        return $this->assetList;
    }

    public function isEmpty(): bool
    {
        return empty($this->assetList);
    }

    public function getAssetsForDates(DateTime $startTime, DateTime $endTime, Event $event): Assets
    {
        $limitedAssets = new Assets();
        /** @var Asset $asset */
        foreach ($this->assetList as $asset) {
            $limitedAssets->addAsset($this->loader->getAsset($asset->getTicker(), $startTime, $endTime, $event));
        }
        return $limitedAssets;
    }
}