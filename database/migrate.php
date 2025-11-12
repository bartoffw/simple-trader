<?php

/**
 * Database Migration Script
 *
 * Initializes both SQLite databases and runs all migrations
 *
 * Usage:
 *   php database/migrate.php
 *   php database/migrate.php --help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;

// Check for help flag
if (isset($argv[1]) && ($argv[1] === '--help' || $argv[1] === '-h')) {
    echo <<<HELP

Simple-Trader Database Migration
=================================

Creates and initializes both SQLite databases with all required tables and indexes.

USAGE:
  php database/migrate.php
  php database/migrate.php --help

DESCRIPTION:
  This script performs the following operations:

  1. Creates runs.db database
     - Initializes runs table for backtest execution history
     - Creates indexes for performance

  2. Creates monitors.db database
     - Initializes monitors table for strategy monitoring configuration
     - Initializes monitor_daily_snapshots for daily state tracking
     - Initializes monitor_trades for trade history
     - Initializes monitor_metrics for performance metrics
     - Creates indexes for fast querying

  3. Creates tickers.db database
     - Initializes tickers table for ticker metadata
     - Initializes quotes table for OHLCV data
     - Creates indexes for fast querying
     - Removes old runs table if it exists (database separation)

WHEN TO RUN:
  - Initial installation (first time setup)
  - After pulling database schema changes
  - When upgrading to new version with schema changes
  - If database files are deleted and need to be recreated

WHAT IT DOES:
  - Automatically creates database files if they don't exist
  - Runs all SQL migration files in order
  - Skips migrations that have already been applied
  - Reports success or failure for each migration
  - Safe to run multiple times (idempotent)

DATABASE FILES:
  - database/runs.db      (backtest run history)
  - database/monitors.db  (strategy monitoring data)
  - database/tickers.db   (ticker and quote data)

MIGRATION FILES:
  - database/runs-migrations/*.sql      (runs database migrations)
  - database/monitors-migrations/*.sql  (monitors database migrations)
  - database/migrations/*.sql           (tickers database migrations)

EXAMPLES:

  1. Run migrations:
     php database/migrate.php

  2. Show this help:
     php database/migrate.php --help

EXIT CODES:
  0  Success - all migrations completed
  1  Error - migration failed or database connection error

NOTES:
  - Requires SQLite PDO extension (pdo_sqlite)
  - Creates database directory structure automatically
  - Backup existing databases before running on production
  - See database/README.md for more information

TROUBLESHOOTING:
  - Error "could not find driver": Install php-sqlite3 or php-pdo-sqlite
  - Permission errors: Check write permissions on database/ directory
  - Migration failed: Check migration file syntax and table names


HELP;
    exit(0);
}

echo "=== Simple-Trader Database Migration ===" . PHP_EOL . PHP_EOL;

$success = true;
$databases = [
    'runs' => [
        'path' => __DIR__ . '/runs.db',
        'migrations' => __DIR__ . '/runs-migrations',
        'name' => 'Runs Database'
    ],
    'monitors' => [
        'path' => __DIR__ . '/monitors.db',
        'migrations' => __DIR__ . '/monitors-migrations',
        'name' => 'Monitors Database'
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
