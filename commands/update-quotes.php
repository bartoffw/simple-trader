<?php

/**
 * Update Ticker Quotes Command
 *
 * Updates quotes for all enabled tickers or a specific ticker
 *
 * Usage:
 *   php commands/update-quotes.php [--ticker-id=X] [--force]
 *   php commands/update-quotes.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Services\QuoteFetcher;
use SimpleTrader\Services\QuoteUpdateService;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Investor\EmailNotifier;

// Parse command line options
$options = getopt('h', ['help', 'ticker-id:', 'force']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

Update Ticker Quotes Command
============================

Updates quotation data for all enabled tickers or a specific ticker.

USAGE:
  php commands/update-quotes.php [options]
  php commands/update-quotes.php --help

OPTIONS:
  --ticker-id=X     Update only the specified ticker ID
  --force           Force update even if quotes are up to date
  -h, --help        Show this help message

DESCRIPTION:
  This command:
  1. Fetches the list of enabled tickers (or specific ticker if --ticker-id provided)
  2. For each ticker, checks for missing quote data
  3. Fetches missing quotes from the data source (Yahoo Finance, TradingView, etc.)
  4. Saves quotes to the database
  5. Logs all operations with detailed status
  6. Sends email notification on completion (if configured)

  The command automatically detects which dates are missing and only fetches
  the necessary data, making it efficient for daily updates.

EXAMPLES:

  1. Update all enabled tickers:
     php commands/update-quotes.php

  2. Update a specific ticker:
     php commands/update-quotes.php --ticker-id=1

  3. Force update (fetch even if up to date):
     php commands/update-quotes.php --force

  4. Update specific ticker with force:
     php commands/update-quotes.php --ticker-id=1 --force

EXIT CODES:
  0  Success - all tickers updated successfully
  1  Partial failure - some tickers failed to update
  2  Complete failure - no tickers updated or system error

CRON USAGE:
  # Update quotes daily at 4:15 PM ET (after market close)
  15 16 * * 1-5 cd /var/www/simple-trader && php commands/update-quotes.php >> /var/log/quotes.log 2>&1

NOTES:
  - Requires valid data source configuration for tickers
  - Respects API rate limits (built-in delays between requests)
  - Logs are written to stdout/stderr
  - Email notifications require SMTP configuration in .env
  - Lock file prevents concurrent execution


HELP;
    exit(0);
}

// Prevent concurrent execution
$lockFile = __DIR__ . '/../var/update-quotes.lock';
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "✗ Another instance is already running\n";
    exit(2);
}

try {
    echo "=== Update Ticker Quotes ===" . PHP_EOL;
    echo "Started: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize databases
    $tickersDb = Database::getInstance($config['database']['tickers']);

    // Initialize repositories
    $tickerRepository = new TickerRepository($tickersDb);
    $quoteRepository = new QuoteRepository($tickersDb);

    // Initialize services
    $quoteFetcher = new QuoteFetcher($quoteRepository, $tickerRepository);
    $quoteUpdateService = new QuoteUpdateService($tickerRepository, $quoteRepository, $quoteFetcher);

    // Initialize logger
    $logger = new Console();
    $logger->setLevel(Level::Info);
    $quoteUpdateService->setLogger($logger);

    // Initialize email notifier (if configured)
    $notifier = null;
    if (getenv('SMTP_HOST')) {
        $notifier = new EmailNotifier(
            getenv('SMTP_HOST'),
            getenv('SMTP_PORT'),
            getenv('SMTP_USER'),
            getenv('SMTP_PASS'),
            getenv('FROM_EMAIL'),
            getenv('TO_EMAIL')
        );
    }

    // Execute update
    $results = null;
    if (isset($options['ticker-id'])) {
        $tickerId = (int)$options['ticker-id'];
        echo "Updating ticker ID: {$tickerId}" . PHP_EOL . PHP_EOL;

        $result = $quoteUpdateService->updateTicker($tickerId);

        $results = [
            'success' => $result['success'] ? 1 : 0,
            'failed' => $result['success'] ? 0 : 1,
            'total' => 1,
            'errors' => $result['success'] ? [] : [$result['message']],
            'details' => [$result]
        ];
    } else {
        echo "Updating all enabled tickers" . PHP_EOL . PHP_EOL;
        $results = $quoteUpdateService->updateAllTickers();
    }

    // Print summary
    echo PHP_EOL . "=== Summary ===" . PHP_EOL;
    echo "Total tickers: {$results['total']}" . PHP_EOL;
    echo "✓ Succeeded: {$results['success']}" . PHP_EOL;
    echo "✗ Failed: {$results['failed']}" . PHP_EOL;

    if (!empty($results['errors'])) {
        echo PHP_EOL . "Errors:" . PHP_EOL;
        foreach ($results['errors'] as $error) {
            echo "  - {$error}" . PHP_EOL;
        }
    }

    echo PHP_EOL . "Completed: " . date('Y-m-d H:i:s') . PHP_EOL;

    // Send email notification
    if ($notifier) {
        $notifier->addSummary('<h2>Quote Update Report</h2>');
        $notifier->addSummary('<p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>');
        $notifier->addSummary('<p><strong>Total Tickers:</strong> ' . $results['total'] . '</p>');
        $notifier->addSummary('<p><strong>Success:</strong> ' . $results['success'] . '</p>');
        $notifier->addSummary('<p><strong>Failed:</strong> ' . $results['failed'] . '</p>');

        if (!empty($results['errors'])) {
            $notifier->addSummary('<h3>Errors</h3><ul>');
            foreach ($results['errors'] as $error) {
                $notifier->addSummary('<li>' . htmlspecialchars($error) . '</li>');
            }
            $notifier->addSummary('</ul>');
        }

        $notifier->addLogs($logger->getLogs());
        $notifier->sendAllNotifications();
    }

    // Exit code
    if ($results['failed'] > 0) {
        exit(1); // Partial failure
    }

    exit(0); // Success

} catch (\Exception $e) {
    echo PHP_EOL . "✗ Fatal Error: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;

    if ($notifier) {
        $notifier->notifyError("Fatal error in update-quotes: " . $e->getMessage());
        $notifier->sendAllNotifications();
    }

    exit(2); // Complete failure
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
