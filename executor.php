<?php

use SimpleTrader\Loaders\TradingViewSource;

require_once __DIR__ . '/vendor/autoload.php';

// 1. create objects for all strategies
// 2. define data source(s)
// 3. define data storage
// 4. check missing day(s) and pull them from data source(s) into data storage
// 5. run all defined strategies on the updated data
// 6. report all successes and failures in steps 1 to 5 (via email)
// 7. report results of the strategies (via email) - entry/exit/keep position
// 8. some strategies may be in observation mode (e.g. index observation) and a current chart will be emailed with some additional details


// TradingView
// https://github.com/Textalk/websocket-php
// https://github.com/0xrushi/tradingview-scraper/issues/1

$source = new TradingViewSource();
echo $source->getQuotes('3DEL', 'MIL');