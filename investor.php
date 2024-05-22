<?php

require_once __DIR__ . '/vendor/autoload.php';

use MammothPHP\WoollyM\IO\CSV;
use SimpleTrader\Assets;
use SimpleTrader\Event;
use SimpleTrader\Investor\EmailNotifier;
use SimpleTrader\Investor\Investment;
use SimpleTrader\Investor\Investor;
use SimpleTrader\Loaders\TradingViewSource;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;
use SimpleTrader\TestStrategy;


$tickers = [
    'QQQ3' => [
        'path' => __DIR__ . '/QQQ3-tv.csv',
        'exchange' => 'MIL'
    ],
];
$stateFile = __DIR__ . '/investments-state.json';

$logger = new Console();
$logger->setLevel(Level::Info);

try {
    // 1. create objects for all strategies
    // 2. define data source(s)
    // 3. define data storage
    $assets = new Assets();
    foreach ($tickers as $ticker => $data) {
        $assets->addAsset(CSV::fromFilePath($data['path'])->import(), $ticker, false, $data['exchange'], $data['path']);
    }

    $investor = new Investor($stateFile);
    $investor->setLogger($logger);
    $investor->setNotifier(new EmailNotifier(getenv('SMTP_HOST'), getenv('SMTP_PORT'), getenv('SMTP_USER'), getenv('SMTP_PASS')));
    $investor->addInvestment(TestStrategy::class, new Investment(new TestStrategy(), new TradingViewSource(), $assets));

    // 4. check missing day(s) and pull them from data source(s) into data storage
    $investor->updateSources();

    // 5. run all defined strategies on the updated data
    $investor->execute(Event::OnClose);

} catch (Exception $e) {
    $logger->logError($e->getMessage());
}

// 6. report all successes and failures in steps 1 to 5 (via email)
// 7. report results of the strategies (via email) - entry/exit/keep position
// 8. some strategies may be in observation mode (e.g. index observation) and a current chart will be emailed with some additional details
