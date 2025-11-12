<?php

/**
 * Import Existing Tickers Script
 *
 * Imports the hardcoded ticker from investor.php into the database
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;

$databasePath = __DIR__ . '/tickers.db';

echo "=== Import Existing Tickers ===" . PHP_EOL;
echo "Database: {$databasePath}" . PHP_EOL . PHP_EOL;

// Check if database exists
if (!file_exists($databasePath)) {
    echo "✗ Database not found. Please run migrate.php first." . PHP_EOL;
    exit(1);
}

try {
    // Initialize database connection
    echo "[1/2] Connecting to database..." . PHP_EOL;
    $database = Database::getInstance($databasePath);
    $repository = new TickerRepository($database);
    echo "✓ Connected successfully" . PHP_EOL . PHP_EOL;

    // Define the existing ticker from investor.php (line 17-22)
    $existingTickers = [
        'IUSQ' => [
            'path' => __DIR__ . '/../IUSQ.csv',
            'exchange' => 'XETR'
        ],
    ];

    echo "[2/2] Importing tickers..." . PHP_EOL;

    $imported = 0;
    $skipped = 0;

    foreach ($existingTickers as $symbol => $data) {
        echo "  → Processing {$symbol}... ";

        // Check if ticker already exists
        $existing = $repository->getTickerBySymbol($symbol);
        if ($existing !== null) {
            echo "⊙ (already exists)" . PHP_EOL;
            $skipped++;
            continue;
        }

        // Check if CSV file exists
        if (!file_exists($data['path'])) {
            echo "⚠ (CSV file not found: {$data['path']})" . PHP_EOL;
            continue;
        }

        // Import the ticker
        try {
            $tickerId = $repository->createTicker([
                'symbol' => $symbol,
                'exchange' => $data['exchange'],
                'csv_path' => $data['path'],
                'enabled' => true
            ]);

            if ($tickerId) {
                echo "✓ (ID: {$tickerId})" . PHP_EOL;
                $imported++;
            } else {
                echo "✗ (failed to create)" . PHP_EOL;
            }
        } catch (Exception $e) {
            echo "✗ (error: {$e->getMessage()})" . PHP_EOL;
        }
    }

    echo PHP_EOL . "=== Import Summary ===" . PHP_EOL;
    echo "Imported: {$imported}" . PHP_EOL;
    echo "Skipped:  {$skipped}" . PHP_EOL;
    echo "Total:    " . ($imported + $skipped) . PHP_EOL;

    // Show current tickers in database
    echo PHP_EOL . "=== Current Tickers in Database ===" . PHP_EOL;
    $allTickers = $repository->getAllTickers();

    if (empty($allTickers)) {
        echo "No tickers found in database." . PHP_EOL;
    } else {
        echo sprintf("%-5s %-10s %-10s %-8s %s", "ID", "Symbol", "Exchange", "Enabled", "CSV Path") . PHP_EOL;
        echo str_repeat("-", 80) . PHP_EOL;

        foreach ($allTickers as $ticker) {
            echo sprintf(
                "%-5d %-10s %-10s %-8s %s",
                $ticker['id'],
                $ticker['symbol'],
                $ticker['exchange'],
                $ticker['enabled'] ? 'Yes' : 'No',
                $ticker['csv_path']
            ) . PHP_EOL;
        }
    }

    echo PHP_EOL . "✓ Import completed successfully!" . PHP_EOL;

} catch (Exception $e) {
    echo "✗ Import failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
