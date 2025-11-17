<?php

/**
 * Backtests Database Migration Script
 *
 * Initializes the SQLite backtests database and runs all migrations
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;

$databasePath = __DIR__ . '/backtests.db';
$migrationsDir = __DIR__ . '/backtests-migrations';

echo "=== Simple-Trader Backtests Database Migration ===" . PHP_EOL;
echo "Database: {$databasePath}" . PHP_EOL . PHP_EOL;

try {
    // Initialize database connection
    echo "[1/2] Connecting to database..." . PHP_EOL;
    $database = Database::getInstance($databasePath);
    echo "✓ Connected successfully" . PHP_EOL . PHP_EOL;

    // Get all migration files
    echo "[2/2] Running migrations..." . PHP_EOL;
    $migrationFiles = glob($migrationsDir . '/*.sql');
    sort($migrationFiles);

    if (empty($migrationFiles)) {
        echo "⚠ No migration files found in {$migrationsDir}" . PHP_EOL;
        exit(1);
    }

    foreach ($migrationFiles as $migrationFile) {
        $filename = basename($migrationFile);
        echo "  → Running {$filename}... ";

        try {
            $database->executeSqlFile($migrationFile);
            echo "✓" . PHP_EOL;
        } catch (Exception $e) {
            echo "✗" . PHP_EOL;
            echo "    Error: " . $e->getMessage() . PHP_EOL;
            exit(1);
        }
    }

    echo PHP_EOL . "✓ All migrations completed successfully!" . PHP_EOL;
    echo PHP_EOL . "Database created at: {$databasePath}" . PHP_EOL;

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
