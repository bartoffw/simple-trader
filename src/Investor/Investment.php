<?php

namespace SimpleTrader\Investor;

use SimpleTrader\Assets;
use SimpleTrader\BaseStrategy;
use SimpleTrader\Loaders\SourceInterface;

class Investment
{
    public function __construct(protected BaseStrategy $strategy, protected SourceInterface $source,
                                protected Assets $assets, protected ?float $capital = null)
    {
        $this->strategy->setTickers($this->assets->getTickers());
    }

    public function getStrategy(): BaseStrategy
    {
        return $this->strategy;
    }

    public function getSource(): SourceInterface
    {
        return $this->source;
    }

    public function getAssets(): Assets
    {
        return $this->assets;
    }

    public function getCapital(): ?float
    {
        return $this->capital;
    }

    public function setAssets(Assets $assets): void
    {
        $this->assets = $assets;
        $this->strategy->setTickers($this->assets->getTickers());
    }
}