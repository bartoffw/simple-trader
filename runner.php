<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Assets;
use SimpleTrader\Backtester;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;
use SimpleTrader\TestStrategy;


$fromDate = new DateTime('2020-01-01');
$toDate = new DateTime('2023-01-31');
$resolution = Resolution::Daily;

try {
    $logger = new Console();
    $assets = new Assets();
    $assets->addAsset(Csv::fromFile('QQQ3', __DIR__ . '/QQQ3.MI.csv'), $fromDate);

    $backtest = new Backtester($resolution);
    $backtest->setLogger($logger);
    $backtest->setStrategy(new TestStrategy());
    $backtest->runBacktest($assets, $fromDate, $toDate);

} catch (\Exception $e) {
    $logger->log(Level::Error, $e->getMessage() . ' at ' . $e->getTraceAsString());
}


//var_dump($loader->getData());
//var_dump($fromDate);