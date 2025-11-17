<?php

/**
 * List Tickers Command
 *
 * Displays all tickers with their pricing data status and metadata.
 * Essential for Claude to understand available data for strategy building.
 *
 * Usage:
 *   php commands/list-tickers.php [--format=human|json] [--enabled-only] [--with-stats]
 *   php commands/list-tickers.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Database\QuoteRepository;

// Parse command line options
$options = getopt('h', ['help', 'format:', 'enabled-only', 'with-stats']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

List Tickers Command
====================

Displays all configured tickers with their pricing data status.

USAGE:
  php commands/list-tickers.php [options]
  php commands/list-tickers.php --help

OPTIONS:
  --format=FORMAT     Output format: 'human' (default) or 'json'
  --enabled-only      Show only enabled tickers
  --with-stats        Include detailed statistics (slower)
  -h, --help          Show this help message

OUTPUT FIELDS:
  - ID: Unique ticker identifier
  - Symbol: Ticker symbol (e.g., AAPL, SPY)
  - Exchange: Stock exchange code
  - Source: Data source for fetching quotes
  - Enabled: Whether ticker is active
  - Quote Count: Number of OHLCV records
  - First Date: Earliest available data
  - Last Date: Most recent data
  - Data Current: Whether data is recent (within 7 days)
  - Has Volume: Whether volume data is available

JSON FORMAT:
  When using --format=json, returns structured data suitable for automated processing:
  {
    "success": true,
    "tickers": [...],
    "summary": {
      "total": int,
      "enabled": int,
      "disabled": int,
      "with_data": int,
      "current_data": int
    }
  }

EXAMPLES:

  1. List all tickers (human readable):
     php commands/list-tickers.php

  2. List in JSON format:
     php commands/list-tickers.php --format=json

  3. List only enabled tickers with statistics:
     php commands/list-tickers.php --enabled-only --with-stats

EXIT CODES:
  0  Success
  1  Error


HELP;
    exit(0);
}

try {
    $format = $options['format'] ?? 'human';
    $enabledOnly = isset($options['enabled-only']);
    $withStats = isset($options['with-stats']);

    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize database
    $tickersDb = Database::getInstance($config['database']['tickers']);

    // Initialize repositories
    $tickerRepository = new TickerRepository($tickersDb);
    $quoteRepository = new QuoteRepository($tickersDb);

    // Fetch tickers
    $tickers = $tickerRepository->getAllTickers($enabledOnly ? true : null);

    // Enrich ticker data
    $enrichedTickers = [];
    $withData = 0;
    $currentData = 0;
    $today = new DateTime();

    foreach ($tickers as $ticker) {
        $tickerData = [
            'id' => $ticker['id'],
            'symbol' => $ticker['symbol'],
            'exchange' => $ticker['exchange'],
            'source' => $ticker['source'],
            'enabled' => (bool)$ticker['enabled'],
            'latest_quote_date' => $ticker['latest_quote_date'] ?? null,
        ];

        // Get quote statistics
        $stats = $quoteRepository->getStatistics($ticker['id']);
        $tickerData['quote_count'] = $stats['count'];
        $tickerData['first_date'] = $stats['first_date'];
        $tickerData['last_date'] = $stats['last_date'];

        // Check if has volume data
        if ($stats['count'] > 0 && $stats['avg_volume'] !== null && $stats['avg_volume'] > 0) {
            $tickerData['has_volume'] = true;
        } else {
            $tickerData['has_volume'] = false;
        }

        // Check if data is current (within 7 days, accounting for weekends)
        $tickerData['data_current'] = false;
        if ($stats['last_date']) {
            $withData++;
            $lastDate = new DateTime($stats['last_date']);
            $daysDiff = $today->diff($lastDate)->days;
            // Consider data current if it's within 7 days (accounts for weekends and holidays)
            if ($daysDiff <= 7) {
                $tickerData['data_current'] = true;
                $currentData++;
            }
        }

        // Add extended stats if requested
        if ($withStats) {
            $tickerData['lowest_price'] = $stats['lowest_price'];
            $tickerData['highest_price'] = $stats['highest_price'];
            $tickerData['avg_volume'] = $stats['avg_volume'];
        }

        $enrichedTickers[] = $tickerData;
    }

    // Prepare summary
    $repoStats = $tickerRepository->getStatistics();
    $summary = [
        'total' => count($tickers),
        'enabled' => $repoStats['enabled'],
        'disabled' => $repoStats['disabled'],
        'with_data' => $withData,
        'current_data' => $currentData
    ];

    // Output based on format
    if ($format === 'json') {
        $output = [
            'success' => true,
            'tickers' => $enrichedTickers,
            'summary' => $summary
        ];
        echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        // Human readable format
        echo "\n=== Ticker List ===\n\n";

        if (empty($enrichedTickers)) {
            echo "No tickers found.\n";
        } else {
            // Header
            $headerFormat = "%-4s %-10s %-8s %-15s %-8s %-10s %-12s %-12s %-8s %-8s\n";
            printf($headerFormat, 'ID', 'Symbol', 'Exchange', 'Source', 'Enabled', 'Quotes', 'First Date', 'Last Date', 'Current', 'Volume');
            echo str_repeat('-', 110) . "\n";

            foreach ($enrichedTickers as $t) {
                $rowFormat = "%-4s %-10s %-8s %-15s %-8s %-10s %-12s %-12s %-8s %-8s\n";
                printf(
                    $rowFormat,
                    $t['id'],
                    $t['symbol'],
                    $t['exchange'],
                    substr($t['source'], 0, 15),
                    $t['enabled'] ? 'Yes' : 'No',
                    $t['quote_count'],
                    $t['first_date'] ?? 'N/A',
                    $t['last_date'] ?? 'N/A',
                    $t['data_current'] ? 'Yes' : 'No',
                    $t['has_volume'] ? 'Yes' : 'No'
                );
            }

            echo "\n=== Summary ===\n";
            echo "Total tickers: {$summary['total']}\n";
            echo "Enabled: {$summary['enabled']}\n";
            echo "Disabled: {$summary['disabled']}\n";
            echo "With data: {$summary['with_data']}\n";
            echo "Current data (≤7 days old): {$summary['current_data']}\n";
        }

        echo "\n";
    }

    exit(0);

} catch (\Exception $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(1);
}
