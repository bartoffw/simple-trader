<?php

/**
 * Daily Update Master Dispatcher
 *
 * Orchestrates all daily update tasks: quotes and monitors
 *
 * Usage:
 *   php commands/daily-update.php [--date=YYYY-MM-DD] [--skip-quotes] [--skip-monitors]
 *   php commands/daily-update.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Investor\EmailNotifier;

// Parse command line options
$options = getopt('h', ['help', 'date:', 'skip-quotes', 'skip-monitors']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

Daily Update Master Dispatcher
===============================

Orchestrates all daily update tasks in the correct order:
1. Update ticker quotes (update-quotes.php)
2. Update strategy monitors (update-monitor.php)

USAGE:
  php commands/daily-update.php [options]
  php commands/daily-update.php --help

OPTIONS:
  --date=YYYY-MM-DD  Date to process (defaults to today)
  --skip-quotes      Skip quote updates (only update monitors)
  --skip-monitors    Skip monitor updates (only update quotes)
  -h, --help         Show this help message

DESCRIPTION:
  This master script executes all daily update tasks in the proper sequence:

  1. Quote Updates (update-quotes.php):
     - Fetches latest quote data for all enabled tickers
     - Saves quotes to database
     - Required before updating monitors

  2. Monitor Updates (update-monitor.php):
     - Processes all active strategy monitors
     - Uses quotes fetched in step 1
     - Updates positions, equity, and metrics

  The script waits for each step to complete before proceeding to the next.
  A consolidated email notification is sent at the end (if configured).

EXAMPLES:

  1. Run full daily update for today:
     php commands/daily-update.php

  2. Run full daily update for a specific date:
     php commands/daily-update.php --date=2025-01-15

  3. Update only quotes (skip monitors):
     php commands/daily-update.php --skip-monitors

  4. Update only monitors (skip quotes):
     php commands/daily-update.php --skip-quotes

EXIT CODES:
  0  Success - all tasks completed successfully
  1  Partial failure - some tasks failed
  2  Complete failure - critical error or all tasks failed

CRON USAGE:
  # Run daily update at 4:30 PM ET (after market close)
  30 16 * * 1-5 cd /var/www/simple-trader && php commands/daily-update.php >> /var/log/daily-update.log 2>&1

  # Or split into separate jobs with delay:
  15 16 * * 1-5 cd /var/www/simple-trader && php commands/update-quotes.php >> /var/log/quotes.log 2>&1
  30 16 * * 1-5 cd /var/www/simple-trader && php commands/update-monitor.php >> /var/log/monitors.log 2>&1

NOTES:
  - Requires quote sources to be configured for tickers
  - Monitors must be in 'active' status to be updated
  - Each sub-command uses its own lock file to prevent concurrent execution
  - Email notifications require SMTP configuration in .env
  - Logs from both commands are collected and included in email
  - Lock file prevents concurrent execution of this dispatcher


HELP;
    exit(0);
}

// Prevent concurrent execution
$lockFile = __DIR__ . '/../var/daily-update.lock';
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "✗ Another instance is already running\n";
    exit(2);
}

try {
    $date = $options['date'] ?? date('Y-m-d');
    $skipQuotes = isset($options['skip-quotes']);
    $skipMonitors = isset($options['skip-monitors']);

    echo "========================================" . PHP_EOL;
    echo "=== Daily Update Master Dispatcher ===" . PHP_EOL;
    echo "========================================" . PHP_EOL;
    echo "Started: " . date('Y-m-d H:i:s') . PHP_EOL;
    echo "Processing date: {$date}" . PHP_EOL;
    echo PHP_EOL;

    $projectRoot = __DIR__ . '/..';
    $results = [
        'quotes' => null,
        'monitors' => null
    ];

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

    // Step 1: Update Quotes
    if (!$skipQuotes) {
        echo "========================================" . PHP_EOL;
        echo "Step 1: Updating Ticker Quotes" . PHP_EOL;
        echo "========================================" . PHP_EOL;
        echo PHP_EOL;

        $quotesCommand = "php " . escapeshellarg($projectRoot . "/commands/update-quotes.php");
        $quotesOutput = [];
        $quotesExitCode = 0;

        exec($quotesCommand . " 2>&1", $quotesOutput, $quotesExitCode);

        // Print output
        foreach ($quotesOutput as $line) {
            echo $line . PHP_EOL;
        }

        $results['quotes'] = [
            'exit_code' => $quotesExitCode,
            'output' => $quotesOutput,
            'success' => $quotesExitCode === 0
        ];

        echo PHP_EOL;

        if ($quotesExitCode !== 0) {
            echo "⚠ Quote update failed with exit code {$quotesExitCode}" . PHP_EOL;
            echo "Continuing with monitor updates..." . PHP_EOL;
            echo PHP_EOL;
        }
    } else {
        echo "Skipping quote updates (--skip-quotes)" . PHP_EOL . PHP_EOL;
    }

    // Step 2: Update Monitors
    if (!$skipMonitors) {
        echo "========================================" . PHP_EOL;
        echo "Step 2: Updating Strategy Monitors" . PHP_EOL;
        echo "========================================" . PHP_EOL;
        echo PHP_EOL;

        $monitorsCommand = "php " . escapeshellarg($projectRoot . "/commands/update-monitor.php");
        if ($date !== date('Y-m-d')) {
            $monitorsCommand .= " --date=" . escapeshellarg($date);
        }

        $monitorsOutput = [];
        $monitorsExitCode = 0;

        exec($monitorsCommand . " 2>&1", $monitorsOutput, $monitorsExitCode);

        // Print output
        foreach ($monitorsOutput as $line) {
            echo $line . PHP_EOL;
        }

        $results['monitors'] = [
            'exit_code' => $monitorsExitCode,
            'output' => $monitorsOutput,
            'success' => $monitorsExitCode === 0
        ];

        echo PHP_EOL;

        if ($monitorsExitCode !== 0) {
            echo "⚠ Monitor update failed with exit code {$monitorsExitCode}" . PHP_EOL;
            echo PHP_EOL;
        }
    } else {
        echo "Skipping monitor updates (--skip-monitors)" . PHP_EOL . PHP_EOL;
    }

    // Print overall summary
    echo "========================================" . PHP_EOL;
    echo "=== Overall Summary ===" . PHP_EOL;
    echo "========================================" . PHP_EOL;

    if (!$skipQuotes) {
        $quotesStatus = $results['quotes']['success'] ? '✓ Success' : '✗ Failed';
        echo "Quotes Update: {$quotesStatus} (exit code: {$results['quotes']['exit_code']})" . PHP_EOL;
    } else {
        echo "Quotes Update: Skipped" . PHP_EOL;
    }

    if (!$skipMonitors) {
        $monitorsStatus = $results['monitors']['success'] ? '✓ Success' : '✗ Failed';
        echo "Monitors Update: {$monitorsStatus} (exit code: {$results['monitors']['exit_code']})" . PHP_EOL;
    } else {
        echo "Monitors Update: Skipped" . PHP_EOL;
    }

    echo PHP_EOL . "Completed: " . date('Y-m-d H:i:s') . PHP_EOL;

    // Send consolidated email notification
    if ($notifier) {
        $notifier->addSummary('<h2>Daily Update Report</h2>');
        $notifier->addSummary('<p><strong>Date:</strong> ' . date('Y-m-d H:i:s') . '</p>');
        $notifier->addSummary('<p><strong>Processing Date:</strong> ' . $date . '</p>');

        $notifier->addSummary('<hr>');

        if (!$skipQuotes) {
            $quotesStatusHtml = $results['quotes']['success']
                ? '<span style="color: green;">✓ Success</span>'
                : '<span style="color: red;">✗ Failed</span>';
            $notifier->addSummary('<h3>Quote Updates: ' . $quotesStatusHtml . '</h3>');
            $notifier->addSummary('<pre>' . htmlspecialchars(implode("\n", $results['quotes']['output'])) . '</pre>');
        }

        if (!$skipMonitors) {
            $monitorsStatusHtml = $results['monitors']['success']
                ? '<span style="color: green;">✓ Success</span>'
                : '<span style="color: red;">✗ Failed</span>';
            $notifier->addSummary('<h3>Monitor Updates: ' . $monitorsStatusHtml . '</h3>');
            $notifier->addSummary('<pre>' . htmlspecialchars(implode("\n", $results['monitors']['output'])) . '</pre>');
        }

        $notifier->sendAllNotifications();
    }

    // Determine exit code
    $hasFailures = false;
    if (!$skipQuotes && !$results['quotes']['success']) {
        $hasFailures = true;
    }
    if (!$skipMonitors && !$results['monitors']['success']) {
        $hasFailures = true;
    }

    if ($hasFailures) {
        exit(1); // Partial failure
    }

    exit(0); // Success

} catch (\Exception $e) {
    echo PHP_EOL . "✗ Fatal Error: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;

    if (isset($notifier) && $notifier) {
        $notifier->notifyError("Fatal error in daily-update: " . $e->getMessage());
        $notifier->sendAllNotifications();
    }

    exit(2); // Complete failure
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
