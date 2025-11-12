<?php

/**
 * Update Strategy Monitors Command
 *
 * Updates active strategy monitors for a specific date
 *
 * Usage:
 *   php commands/update-monitor.php [--monitor-id=X] [--date=YYYY-MM-DD]
 *   php commands/update-monitor.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\MonitorRepository;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;
use SimpleTrader\Services\MonitorUpdateService;
use SimpleTrader\Loggers\Console;
use SimpleTrader\Loggers\Level;
use SimpleTrader\Investor\EmailNotifier;

// Parse command line options
$options = getopt('h', ['help', 'monitor-id:', 'date:']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

Update Strategy Monitors Command
=================================

Updates active strategy monitors for a specific date (default: today).

USAGE:
  php commands/update-monitor.php [options]
  php commands/update-monitor.php --help

OPTIONS:
  --monitor-id=X     Update only the specified monitor ID
  --date=YYYY-MM-DD  Date to process (defaults to today)
  -h, --help         Show this help message

DESCRIPTION:
  This command:
  1. Fetches the list of active monitors (or specific monitor if --monitor-id provided)
  2. For each monitor, checks if already processed for the date
  3. Verifies that quote data is available for the date
  4. Loads the previous state from the latest daily snapshot
  5. Executes the strategy using historical data (lookback period + current date)
  6. Saves daily snapshot with current positions, equity, and strategy state
  7. Records any trades executed on this date
  8. Updates forward test metrics
  9. Logs all operations with detailed status
  10. Sends email notification on completion (if configured)

  The command is idempotent - it will skip monitors that have already been
  processed for the specified date.

EXAMPLES:

  1. Update all active monitors for today:
     php commands/update-monitor.php

  2. Update all active monitors for a specific date:
     php commands/update-monitor.php --date=2025-01-15

  3. Update a specific monitor for today:
     php commands/update-monitor.php --monitor-id=1

  4. Update specific monitor for a specific date:
     php commands/update-monitor.php --monitor-id=1 --date=2025-01-15

EXIT CODES:
  0  Success - all monitors updated successfully
  1  Partial failure - some monitors failed or were skipped
  2  Complete failure - no monitors updated or system error

CRON USAGE:
  # Update monitors daily at 4:30 PM ET (after quotes are updated)
  30 16 * * 1-5 cd /var/www/simple-trader && php commands/update-monitor.php >> /var/log/monitors.log 2>&1

NOTES:
  - Monitors must be in 'active' status to be updated
  - Requires quote data to be available for the date being processed
  - Run update-quotes.php first to ensure quotes are current
  - Strategy state is preserved between days via daily snapshots
  - Logs are written to stdout/stderr
  - Email notifications require SMTP configuration in .env
  - Lock file prevents concurrent execution


HELP;
    exit(0);
}

// Prevent concurrent execution
$lockFile = __DIR__ . '/../var/update-monitor.lock';
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "✗ Another instance is already running\n";
    exit(2);
}

try {
    $date = $options['date'] ?? date('Y-m-d');

    echo "=== Update Strategy Monitors ===" . PHP_EOL;
    echo "Started: " . date('Y-m-d H:i:s') . PHP_EOL;
    echo "Processing date: {$date}" . PHP_EOL . PHP_EOL;

    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize databases
    $tickersDb = Database::getInstance($config['database']['tickers']);
    $monitorsDb = Database::getInstance($config['database']['monitors']);

    // Initialize repositories
    $monitorRepository = new MonitorRepository($monitorsDb);
    $tickerRepository = new TickerRepository($tickersDb);
    $quoteRepository = new QuoteRepository($tickersDb);

    // Initialize service
    $monitorUpdateService = new MonitorUpdateService(
        $monitorRepository,
        $tickerRepository,
        $quoteRepository
    );

    // Initialize logger
    $logger = new Console();
    $logger->setLevel(Level::Info);
    $monitorUpdateService->setLogger($logger);

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
    if (isset($options['monitor-id'])) {
        $monitorId = (int)$options['monitor-id'];
        echo "Updating monitor ID: {$monitorId}" . PHP_EOL . PHP_EOL;

        $result = $monitorUpdateService->updateMonitor($monitorId, $date);

        $results = [
            'success' => $result['success'] && !$result['skipped'] ? 1 : 0,
            'failed' => !$result['success'] && !$result['skipped'] ? 1 : 0,
            'skipped' => $result['skipped'] ? 1 : 0,
            'total' => 1,
            'errors' => $result['success'] ? [] : [$result['message']],
            'details' => [$result]
        ];
    } else {
        echo "Updating all active monitors" . PHP_EOL . PHP_EOL;
        $results = $monitorUpdateService->updateAllMonitors($date);
    }

    // Print summary
    echo PHP_EOL . "=== Summary ===" . PHP_EOL;
    echo "Total monitors: {$results['total']}" . PHP_EOL;
    echo "✓ Succeeded: {$results['success']}" . PHP_EOL;
    echo "⊘ Skipped: {$results['skipped']}" . PHP_EOL;
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
        $notifier->addSummary('<h2>Monitor Update Report</h2>');
        $notifier->addSummary('<p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>');
        $notifier->addSummary('<p><strong>Processing Date:</strong> ' . $date . '</p>');
        $notifier->addSummary('<p><strong>Total Monitors:</strong> ' . $results['total'] . '</p>');
        $notifier->addSummary('<p><strong>Success:</strong> ' . $results['success'] . '</p>');
        $notifier->addSummary('<p><strong>Skipped:</strong> ' . $results['skipped'] . '</p>');
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

    if (isset($notifier) && $notifier) {
        $notifier->notifyError("Fatal error in update-monitor: " . $e->getMessage());
        $notifier->sendAllNotifications();
    }

    exit(2); // Complete failure
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
