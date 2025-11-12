<?php

/**
 * Database Migration Script
 *
 * Initializes both SQLite databases and runs all migrations
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;

echo "=== Simple-Trader Database Migration ===" . PHP_EOL . PHP_EOL;

$success = true;
$databases = [
    'runs' => [
        'path' => __DIR__ . '/runs.db',
        'migrations' => __DIR__ . '/runs-migrations',
        'name' => 'Runs Database'
    ],
    'tickers' => [
        'path' => __DIR__ . '/tickers.db',
        'migrations' => __DIR__ . '/migrations',
        'name' => 'Tickers Database'
    ]
];

try {
    foreach ($databases as $key => $config) {
        echo "=== {$config['name']} ===" . PHP_EOL;
        echo "Database: {$config['path']}" . PHP_EOL;
        echo "Migrations: {$config['migrations']}" . PHP_EOL . PHP_EOL;

        // Initialize database connection
        echo "[1/2] Connecting to database..." . PHP_EOL;
        $database = Database::getInstance($config['path']);
        echo "✓ Connected successfully" . PHP_EOL . PHP_EOL;

        // Get all migration files
        echo "[2/2] Running migrations..." . PHP_EOL;
        $migrationFiles = glob($config['migrations'] . '/*.sql');

        if (empty($migrationFiles)) {
            echo "⚠ No migration files found in {$config['migrations']}" . PHP_EOL;
            echo "Skipping..." . PHP_EOL . PHP_EOL;
            continue;
        }

        sort($migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $filename = basename($migrationFile);
            echo "  → Running {$filename}... ";

            try {
                $database->executeSqlFile($migrationFile);
                echo "✓" . PHP_EOL;
            } catch (Exception $e) {
                echo "✗" . PHP_EOL;
                echo "    Error: " . $e->getMessage() . PHP_EOL;
                $success = false;
                break 2; // Exit both loops
            }
        }

        echo PHP_EOL . "✓ {$config['name']} migrations completed!" . PHP_EOL;
        echo "Database: {$config['path']}" . PHP_EOL . PHP_EOL;
    }

    if ($success) {
        echo "=== ✓ All Migrations Completed Successfully! ===" . PHP_EOL . PHP_EOL;
        echo "Databases created:" . PHP_EOL;
        foreach ($databases as $config) {
            echo "  - {$config['path']}" . PHP_EOL;
        }
        echo PHP_EOL;
    }

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (!$success) {
    exit(1);
}
