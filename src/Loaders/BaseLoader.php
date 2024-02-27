<?php

namespace SimpleTrader\Loaders;

class BaseLoader
{
    protected bool $isLoaded = false;


    public function __call(string $name, array $arguments)
    {

    }

    public function isLoaded():bool
    {
        return $this->isLoaded;
    }
}