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

    public function addAsset(string $ticker, LoaderInterface $loader, $replace = false)
    {
        if (isset($this->assetList[$ticker]) && !$replace) {
            throw new LoaderException('This asset is already loaded: ' . $ticker);
        }
        $this->assetList[$ticker] = $loader;
    }

    public function isEmpty()
    {
        return empty($this->assetList);
    }
}