<?php

/**
 * Monitor Backtest Command
 *
 * Executes initial backtest for a monitor
 *
 * Usage:
 *   php commands/monitor-backtest.php <monitor-id>
 *   php commands/monitor-backtest.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\MonitorRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Services\MonitorBacktestService;

// Check for help flag
if (isset($argv[1]) && ($argv[1] === '--help' || $argv[1] === '-h')) {
    echo <<<HELP

Monitor Backtest Command
========================

Executes the initial backtest for a strategy monitor from the start date to today.

USAGE:
  php commands/monitor-backtest.php <monitor-id>
  php commands/monitor-backtest.php --help

ARGUMENTS:
  monitor-id    The ID of the monitor to run backtest for (required)

DESCRIPTION:
  This command:
  1. Loads the monitor configuration (strategy, tickers, parameters)
  2. Fetches historical quote data for all tickers
  3. Executes the strategy backtest from start_date to today
  4. Saves daily snapshots (equity, positions, state)
  5. Saves all trades executed during backtest
  6. Calculates and saves performance metrics
  7. Updates monitor status to 'active' when complete

EXAMPLES:

  1. Run backtest for monitor ID 1:
     php commands/monitor-backtest.php 1

  2. Show this help:
     php commands/monitor-backtest.php --help

EXIT CODES:
  0  Success - backtest completed successfully
  1  Error - backtest failed or invalid arguments

NOTES:
  - Monitor must exist in database
  - Monitor must have valid tickers and strategy
  - Tickers must have quote data for the specified date range
  - This command is typically called automatically when a monitor is created
  - Progress is tracked in the database and can be monitored via the UI


HELP;
    exit(0);
}

// Check arguments
if (!isset($argv[1])) {
    echo "Error: Monitor ID is required\n";
    echo "Usage: php commands/monitor-backtest.php <monitor-id>\n";
    echo "Try: php commands/monitor-backtest.php --help\n";
    exit(1);
}

$monitorId = (int)$argv[1];

if ($monitorId <= 0) {
    echo "Error: Invalid monitor ID: {$argv[1]}\n";
    exit(1);
}

try {
    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize databases
    $tickersDb = Database::getInstance($config['database']['tickers']);
    $monitorsDb = Database::getInstance($config['database']['monitors']);

    // Initialize repositories
    $tickerRepository = new TickerRepository($tickersDb);
    $quoteRepository = new QuoteRepository($tickersDb);
    $monitorRepository = new MonitorRepository($monitorsDb);

    // Initialize service
    $backtestService = new MonitorBacktestService(
        $monitorRepository,
        $tickerRepository,
        $quoteRepository
    );

    echo "=== Monitor Backtest ===" . PHP_EOL;
    echo "Monitor ID: {$monitorId}" . PHP_EOL;
    echo "Starting backtest execution..." . PHP_EOL . PHP_EOL;

    // Execute backtest
    $success = $backtestService->executeBacktest($monitorId);

    if ($success) {
        echo PHP_EOL . "✓ Backtest completed successfully!" . PHP_EOL;
        exit(0);
    } else {
        echo PHP_EOL . "✗ Backtest failed. Check monitor backtest_error field for details." . PHP_EOL;
        exit(1);
    }

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}
