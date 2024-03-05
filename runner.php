<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Assets;
use SimpleTrader\Backtester;
use SimpleTrader\Helpers\Asset;
use SimpleTrader\Helpers\DateTime;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Loaders\SQLite;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;
use SimpleTrader\TestStrategy;


$fromDate = new DateTime('2020-01-01');
$toDate = new DateTime('2020-01-31');

$logger = new Console();
try {
    $assets = new Assets();
    $assets->setLoader(new SQLite(__DIR__ . '/securities.sqlite'));
    $assets->addAsset(new Asset('QQQ3'));

    $strategy = new TestStrategy();
    $strategy->setCapital('10000');

    $backtest = new Backtester(Resolution::Daily);
    $backtest->setLogger($logger);
    $backtest->setStrategy($strategy);
    $backtest->runBacktest($assets, $fromDate, $toDate);

} catch (\Exception $e) {
    $logger->log(Level::Error, $e->getMessage() . ' at ' . $e->getTraceAsString());
}


//var_dump($loader->getData());
//var_dump($fromDate);