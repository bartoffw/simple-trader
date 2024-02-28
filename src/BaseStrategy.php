<?php

namespace SimpleTrader;

class BaseStrategy
{
    protected array $openTrades = [];


    public function __construct()
    {

    }

    public function onOpen(Assets $assets, DateTime $dateTime)
    {

    }

    public function onClose(Assets $assets, DateTime $dateTime)
    {

    }

    public function buy()
    {

    }

    public function sell()
    {

    }

    public function closeAll()
    {

    }
}