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
use SimpleTrader\Reporting\HtmlReport;
use SimpleTrader\TestStrategy;


$fromDate = new DateTime('2019-12-01');
$toDate = new DateTime('2023-12-31');

$logger = new Console();
$logger->setLevel(Level::Info);
try {
    $assets = new Assets();
    $assets->setLoader(new SQLite(__DIR__ . '/securities.sqlite'));
    $assets->addAsset(new Asset('QQQ3'));

    $strategy = new \SimpleTrader\MaSurferStrategy();
    $strategy->setCapital('10000');

    $backtest = new Backtester(Resolution::Daily);
    $backtest->setLogger($logger);
    $backtest->setStrategy($strategy);
    $backtest->runBacktest($assets, $fromDate, $toDate);

    $logger->logInfo('Backtest run in ' . number_format($backtest->getLastBacktestTime(), 2) . 's');

    $report = new HtmlReport(__DIR__);
    $report->generateReport($backtest);

} catch (\Exception $e) {
    $logger->logError($e->getMessage() . ' at ' . $e->getTraceAsString());
}


//var_dump($loader->getData());
//var_dump($fromDate);