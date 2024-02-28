<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Assets;
use SimpleTrader\Backtester;
use SimpleTrader\DateTime;
use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loggers\Loggers;
use SimpleTrader\Resolution;
use SimpleTrader\TestStrategy;


$fromDate = new DateTime('2020-01-01');
$toDate = new DateTime('2023-12-31');
$resolution = Resolution::Daily;

try {
    $assets = new Assets();
    $assets->addAsset(new Csv('QQQ3', __DIR__ . '/QQQ3.MI.csv'), $fromDate);

    $backtest = new Backtester($resolution);
    $backtest->setLoggers([
        Loggers::Console
    ]);
    $backtest->setStrategy(new TestStrategy());
    $backtest->runBacktest($assets, $fromDate, $toDate);

} catch (\Exception $e) {

}


//var_dump($loader->getData());
//var_dump($fromDate);