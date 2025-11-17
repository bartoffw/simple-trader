<?php

/**
 * Add Ticker Command
 *
 * Adds a new ticker to the system for data fetching and strategy testing.
 *
 * Usage:
 *   php commands/add-ticker.php --symbol=AAPL --exchange=NASDAQ --source=TradingViewSource
 *   php commands/add-ticker.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;
use SimpleTrader\Helpers\SourceDiscovery;

// Parse command line options
$options = getopt('h', ['help', 'symbol:', 'exchange:', 'source:', 'disabled', 'format:', 'fetch-quotes']);

// Check for help flag
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP

Add Ticker Command
==================

Adds a new ticker symbol to the system for tracking and strategy testing.

USAGE:
  php commands/add-ticker.php --symbol=<SYMBOL> --exchange=<EXCHANGE> --source=<SOURCE> [options]
  php commands/add-ticker.php --help

REQUIRED OPTIONS:
  --symbol=SYMBOL     Ticker symbol (e.g., AAPL, SPY, BTC-USD)
  --exchange=EXCHANGE Stock exchange code (e.g., NASDAQ, NYSE, CRYPTO)
  --source=SOURCE     Data source class (e.g., TradingViewSource, YahooSource)

OPTIONAL OPTIONS:
  --disabled          Add ticker in disabled state
  --fetch-quotes      Fetch initial quotes after adding
  --format=FORMAT     Output format: 'human' (default) or 'json'
  -h, --help          Show this help message

AVAILABLE DATA SOURCES:
  The source must be a valid data source class. Common sources:
  - TradingViewSource: Real-time data from TradingView
  - YahooSource: Historical data from Yahoo Finance (if implemented)

  Use 'php commands/list-sources.php' to see all available sources (if available).

EXAMPLES:

  1. Add Apple stock:
     php commands/add-ticker.php --symbol=AAPL --exchange=NASDAQ --source=TradingViewSource

  2. Add S&P 500 ETF:
     php commands/add-ticker.php --symbol=SPY --exchange=NYSE --source=TradingViewSource

  3. Add ticker and immediately fetch quotes:
     php commands/add-ticker.php --symbol=MSFT --exchange=NASDAQ --source=TradingViewSource --fetch-quotes

  4. Add in JSON format for scripting:
     php commands/add-ticker.php --symbol=GOOGL --exchange=NASDAQ --source=TradingViewSource --format=json

EXIT CODES:
  0  Success - ticker added
  1  Error - validation failed or ticker already exists
  2  Error - system error


HELP;
    exit(0);
}

// Validate required options
$requiredOptions = ['symbol', 'exchange', 'source'];
$missingOptions = [];

foreach ($requiredOptions as $opt) {
    if (!isset($options[$opt]) || empty($options[$opt])) {
        $missingOptions[] = "--{$opt}";
    }
}

if (!empty($missingOptions)) {
    echo "✗ Error: Missing required options: " . implode(', ', $missingOptions) . PHP_EOL;
    echo "Use --help for usage information." . PHP_EOL;
    exit(1);
}

try {
    $format = $options['format'] ?? 'human';
    $symbol = strtoupper(trim($options['symbol']));
    $exchange = strtoupper(trim($options['exchange']));
    $source = trim($options['source']);
    $enabled = !isset($options['disabled']);
    $fetchQuotes = isset($options['fetch-quotes']);

    // Validate source
    if (!SourceDiscovery::isValidSource($source)) {
        $availableSources = SourceDiscovery::getAvailableSources();
        $sourceNames = array_map(fn($s) => $s['class_name'], $availableSources);

        if ($format === 'json') {
            echo json_encode([
                'success' => false,
                'error' => "Invalid source: {$source}",
                'available_sources' => $sourceNames
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            echo "✗ Error: Invalid source '{$source}'" . PHP_EOL;
            echo "Available sources: " . implode(', ', $sourceNames) . PHP_EOL;
        }
        exit(1);
    }

    // Load configuration
    $config = require __DIR__ . '/../config/config.php';

    // Initialize database
    $tickersDb = Database::getInstance($config['database']['tickers']);

    // Initialize repository
    $tickerRepository = new TickerRepository($tickersDb);

    // Validate ticker data
    $tickerData = [
        'symbol' => $symbol,
        'exchange' => $exchange,
        'source' => $source,
        'enabled' => $enabled
    ];

    $errors = $tickerRepository->validateTickerData($tickerData);
    if (!empty($errors)) {
        if ($format === 'json') {
            echo json_encode([
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $errors
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            echo "✗ Validation errors:" . PHP_EOL;
            foreach ($errors as $field => $error) {
                echo "  - {$field}: {$error}" . PHP_EOL;
            }
        }
        exit(1);
    }

    // Create ticker
    $tickerId = $tickerRepository->createTicker($tickerData);

    if ($tickerId === false) {
        if ($format === 'json') {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to create ticker'
            ], JSON_PRETTY_PRINT) . PHP_EOL;
        } else {
            echo "✗ Failed to create ticker" . PHP_EOL;
        }
        exit(2);
    }

    // Get the created ticker
    $ticker = $tickerRepository->getTicker($tickerId);

    $result = [
        'success' => true,
        'message' => "Ticker '{$symbol}' added successfully",
        'ticker' => [
            'id' => $ticker['id'],
            'symbol' => $ticker['symbol'],
            'exchange' => $ticker['exchange'],
            'source' => $ticker['source'],
            'enabled' => (bool)$ticker['enabled'],
            'created_at' => $ticker['created_at']
        ]
    ];

    // Fetch quotes if requested
    if ($fetchQuotes) {
        // Execute update-quotes for this ticker
        $command = "php " . __DIR__ . "/update-quotes.php --ticker-id={$tickerId}";
        $output = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);

        $result['quote_fetch'] = [
            'executed' => true,
            'success' => $returnCode === 0,
            'message' => $returnCode === 0 ? 'Quotes fetched successfully' : 'Quote fetch had issues (see logs)',
            'return_code' => $returnCode
        ];
    }

    if ($format === 'json') {
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "\n✓ " . $result['message'] . PHP_EOL;
        echo "\nTicker Details:" . PHP_EOL;
        echo "  ID: {$ticker['id']}" . PHP_EOL;
        echo "  Symbol: {$ticker['symbol']}" . PHP_EOL;
        echo "  Exchange: {$ticker['exchange']}" . PHP_EOL;
        echo "  Source: {$ticker['source']}" . PHP_EOL;
        echo "  Enabled: " . ($ticker['enabled'] ? 'Yes' : 'No') . PHP_EOL;
        echo "  Created: {$ticker['created_at']}" . PHP_EOL;

        if ($fetchQuotes) {
            echo "\nQuote Fetch: " . ($result['quote_fetch']['success'] ? '✓ Success' : '⚠ Completed with issues') . PHP_EOL;
        }

        echo "\nTo fetch quotes for this ticker, run:" . PHP_EOL;
        echo "  php commands/update-quotes.php --ticker-id={$tickerId}" . PHP_EOL;
        echo "\n";
    }

    exit(0);

} catch (\RuntimeException $e) {
    // Ticker already exists or validation error
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(1);
} catch (\Exception $e) {
    if (($options['format'] ?? 'human') === 'json') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo "✗ System Error: " . $e->getMessage() . PHP_EOL;
    }
    exit(2);
}
