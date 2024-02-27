<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\DateTime;
use SimpleTrader\Loaders\Csv;

$fromDate = new DateTime('2020-01-01');
$toDate = new DateTime('2023-12-31');

$loader = new Csv(__DIR__ . '/QQQ3.MI.csv');

$loader->loadData($fromDate);
//var_dump($loader->getData());
//var_dump($fromDate);