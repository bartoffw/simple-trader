<?php

require_once __DIR__ . '/vendor/autoload.php';

use Carbon\Carbon;
use MammothPHP\WoollyM\IO\CSV;
use SimpleTrader\Assets;
use SimpleTrader\Backtester;
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Reporting\HtmlReport;
use SimpleTrader\TestStrategy;


$tickers = [
    'IUSQ' => __DIR__ . '/IUSQ.csv',
];
$benchmark = 'IUSQ';
$benchmarkFile = __DIR__ . '/IUSQ.csv';

$fromDate = new Carbon('2020-01-01');
$toDate = new Carbon('2023-12-31');

$logger = new Console();
$logger->setLevel(Level::Info);
try {
    $assets = new Assets();
    foreach ($tickers as $ticker => $path) {
        $assets->addAsset(CSV::fromFilePath($path)->import(), $ticker);
    }

    $strategy = new TestStrategy(paramsOverrides: [
        'length' => 200
    ]);
    $strategy->setCapital(10000);
    $strategy->setLogger($logger);
    $strategy->setTickers(array_keys($tickers));

    $backtest = new Backtester(Resolution::Daily);
    $backtest->setLogger($logger);
    $backtest->setStrategy($strategy);
    $backtest->setBenchmark(CSV::fromFilePath($benchmarkFile)->import(), $benchmark);
    $backtest->runBacktest($assets, $fromDate, $toDate);

    $logger->logInfo('Backtest run in ' . number_format($backtest->getLastBacktestTime(), 2) . 's');

    $report = new HtmlReport(__DIR__);
    $report->generateReport($backtest, array_keys($tickers));

} catch (\Exception $e) {
    $logger->logError($e->getMessage() . ' at ' . $e->getTraceAsString());
}
