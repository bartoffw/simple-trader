<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loaders\SQLite;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;


$ticker = 'QQQ3';
$logger = new Console();
try {
    $csvData = Csv::fromFile($ticker, __DIR__ . '/QQQ3.MI.csv');
    $csvData->loadData();
    $sqlite = new SQLite(__DIR__ . '/securities.sqlite');

    $sqlite->importData($ticker, $csvData->getData());
} catch (\Exception $e) {
    $logger->log(Level::Error, $e->getMessage() . ' at ' . $e->getTraceAsString());
}