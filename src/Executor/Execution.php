<?php

namespace SimpleTrader\Executor;

use SimpleTrader\Assets;
use SimpleTrader\BaseStrategy;
use SimpleTrader\Loaders\SourceInterface;

class Execution
{
    public function __construct(protected BaseStrategy $strategy, protected SourceInterface $source, protected Assets $assets)
    {
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
}