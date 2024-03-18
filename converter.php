<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loaders\SQLite;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;


$ticker = 'QQQ3';
$logger = new Console();
try {
    $logger->logInfo('Importing data for ' . $ticker);
    $csvData = Csv::fromFile($ticker, __DIR__ . '/QQQ3.tv.csv');
    $csvData->loadData();
    $sqlite = new SQLite(__DIR__ . '/securities.sqlite');

    $sqlite->importData($ticker, $csvData->getData());
    $logger->logInfo('Import finished');
} catch (\Exception $e) {
    $logger->logError($e->getMessage() . ' at ' . $e->getTraceAsString());
}