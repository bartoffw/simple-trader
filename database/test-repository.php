<?php

/**
 * Test Script for TickerRepository
 *
 * Tests all CRUD operations and validates the database functionality
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;

$databasePath = __DIR__ . '/tickers.db';

echo "=== TickerRepository Test Suite ===" . PHP_EOL;
echo "Database: {$databasePath}" . PHP_EOL . PHP_EOL;

// Check if database exists
if (!file_exists($databasePath)) {
    echo "✗ Database not found. Please run migrate.php first." . PHP_EOL;
    exit(1);
}

$testsPassed = 0;
$testsFailed = 0;

function runTest(string $testName, callable $testFunction, &$passed, &$failed): void
{
    echo "→ {$testName}... ";
    try {
        $result = $testFunction();
        if ($result === true) {
            echo "✓ PASS" . PHP_EOL;
            $passed++;
        } else {
            echo "✗ FAIL: {$result}" . PHP_EOL;
            $failed++;
        }
    } catch (Exception $e) {
        echo "✗ ERROR: " . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

try {
    // Initialize database connection
    $database = Database::getInstance($databasePath);
    $repository = new TickerRepository($database);

    echo "=== Testing CRUD Operations ===" . PHP_EOL . PHP_EOL;

    // Test 1: Get all tickers (should include IUSQ if imported)
    runTest("Get all tickers", function() use ($repository) {
        $tickers = $repository->getAllTickers();
        return is_array($tickers) ? true : "Expected array, got " . gettype($tickers);
    }, $testsPassed, $testsFailed);

    // Test 2: Get enabled tickers only
    runTest("Get enabled tickers only", function() use ($repository) {
        $tickers = $repository->getAllTickers(true);
        foreach ($tickers as $ticker) {
            if (!$ticker['enabled']) {
                return "Found disabled ticker in enabled-only results";
            }
        }
        return true;
    }, $testsPassed, $testsFailed);

    // Test 3: Get enabled tickers formatted for investor.php
    runTest("Get enabled tickers formatted", function() use ($repository) {
        $tickers = $repository->getEnabledTickers();
        if (!is_array($tickers)) {
            return "Expected array";
        }
        foreach ($tickers as $symbol => $data) {
            if (!isset($data['path']) || !isset($data['exchange'])) {
                return "Missing required keys in formatted ticker";
            }
        }
        return true;
    }, $testsPassed, $testsFailed);

    // Test 4: Create a new test ticker
    $testTickerId = null;
    runTest("Create new ticker", function() use ($repository, &$testTickerId) {
        $testTickerId = $repository->createTicker([
            'symbol' => 'TEST1',
            'exchange' => 'XETR',
            'csv_path' => __DIR__ . '/../TEST1.csv',
            'enabled' => true
        ]);
        return $testTickerId > 0 ? true : "Failed to create ticker";
    }, $testsPassed, $testsFailed);

    // Test 5: Get ticker by ID
    runTest("Get ticker by ID", function() use ($repository, $testTickerId) {
        if ($testTickerId === null) {
            return "Skipped (no test ticker created)";
        }
        $ticker = $repository->getTicker($testTickerId);
        if ($ticker === null) {
            return "Ticker not found";
        }
        return $ticker['symbol'] === 'TEST1' ? true : "Wrong ticker returned";
    }, $testsPassed, $testsFailed);

    // Test 6: Get ticker by symbol
    runTest("Get ticker by symbol", function() use ($repository) {
        $ticker = $repository->getTickerBySymbol('TEST1');
        if ($ticker === null) {
            return "Ticker not found";
        }
        return $ticker['symbol'] === 'TEST1' ? true : "Wrong ticker returned";
    }, $testsPassed, $testsFailed);

    // Test 7: Update ticker
    runTest("Update ticker", function() use ($repository, $testTickerId) {
        if ($testTickerId === null) {
            return "Skipped (no test ticker created)";
        }
        $result = $repository->updateTicker($testTickerId, [
            'exchange' => 'NYSE'
        ]);
        if (!$result) {
            return "Update failed";
        }
        $ticker = $repository->getTicker($testTickerId);
        return $ticker['exchange'] === 'NYSE' ? true : "Exchange not updated";
    }, $testsPassed, $testsFailed);

    // Test 8: Toggle enabled status
    runTest("Toggle enabled status", function() use ($repository, $testTickerId) {
        if ($testTickerId === null) {
            return "Skipped (no test ticker created)";
        }
        $newStatus = $repository->toggleEnabled($testTickerId);
        if ($newStatus !== false) {
            return "Expected disabled, got enabled";
        }
        $ticker = $repository->getTicker($testTickerId);
        return $ticker['enabled'] == 0 ? true : "Status not toggled";
    }, $testsPassed, $testsFailed);

    // Test 9: Get statistics
    runTest("Get statistics", function() use ($repository) {
        $stats = $repository->getStatistics();
        if (!isset($stats['total']) || !isset($stats['enabled']) || !isset($stats['disabled'])) {
            return "Missing statistics keys";
        }
        if ($stats['total'] !== ($stats['enabled'] + $stats['disabled'])) {
            return "Statistics don't add up";
        }
        return true;
    }, $testsPassed, $testsFailed);

    // Test 10: Validate ticker data
    runTest("Validate ticker data (valid)", function() use ($repository) {
        $errors = $repository->validateTickerData([
            'symbol' => 'VALID',
            'exchange' => 'NYSE',
            'csv_path' => '/path/to/file.csv'
        ]);
        return empty($errors) ? true : "Validation failed: " . json_encode($errors);
    }, $testsPassed, $testsFailed);

    // Test 11: Validate ticker data (invalid - missing symbol)
    runTest("Validate ticker data (invalid - missing symbol)", function() use ($repository) {
        $errors = $repository->validateTickerData([
            'exchange' => 'NYSE',
            'csv_path' => '/path/to/file.csv'
        ]);
        return isset($errors['symbol']) ? true : "Should have symbol error";
    }, $testsPassed, $testsFailed);

    // Test 12: Validate ticker data (invalid - symbol too long)
    runTest("Validate ticker data (invalid - symbol too long)", function() use ($repository) {
        $errors = $repository->validateTickerData([
            'symbol' => 'VERYLONGSYMBOL123',
            'exchange' => 'NYSE',
            'csv_path' => '/path/to/file.csv'
        ]);
        return isset($errors['symbol']) ? true : "Should have symbol length error";
    }, $testsPassed, $testsFailed);

    // Test 13: Validate ticker data (invalid - path traversal)
    runTest("Validate ticker data (invalid - path traversal)", function() use ($repository) {
        $errors = $repository->validateTickerData([
            'symbol' => 'HACK',
            'exchange' => 'NYSE',
            'csv_path' => '../../../etc/passwd'
        ]);
        return isset($errors['csv_path']) ? true : "Should have path traversal error";
    }, $testsPassed, $testsFailed);

    // Test 14: Create duplicate ticker (should fail)
    runTest("Create duplicate ticker (should fail)", function() use ($repository) {
        try {
            $repository->createTicker([
                'symbol' => 'TEST1',
                'exchange' => 'XETR',
                'csv_path' => __DIR__ . '/../TEST1.csv',
                'enabled' => true
            ]);
            return "Should have thrown exception for duplicate";
        } catch (RuntimeException $e) {
            return strpos($e->getMessage(), 'already exists') !== false ? true : "Wrong exception message";
        }
    }, $testsPassed, $testsFailed);

    // Test 15: Get audit log
    runTest("Get audit log", function() use ($repository, $testTickerId) {
        if ($testTickerId === null) {
            return "Skipped (no test ticker created)";
        }
        $log = $repository->getAuditLog($testTickerId);
        if (!is_array($log)) {
            return "Expected array";
        }
        // Should have at least 3 entries: created, updated, disabled
        return count($log) >= 3 ? true : "Expected at least 3 audit entries, got " . count($log);
    }, $testsPassed, $testsFailed);

    // Test 16: Delete ticker
    runTest("Delete ticker", function() use ($repository, $testTickerId) {
        if ($testTickerId === null) {
            return "Skipped (no test ticker created)";
        }
        $result = $repository->deleteTicker($testTickerId);
        if (!$result) {
            return "Delete failed";
        }
        $ticker = $repository->getTicker($testTickerId);
        return $ticker === null ? true : "Ticker still exists after delete";
    }, $testsPassed, $testsFailed);

    // Test 17: Try to get deleted ticker (should return null)
    runTest("Get deleted ticker (should be null)", function() use ($repository, $testTickerId) {
        if ($testTickerId === null) {
            return "Skipped (no test ticker created)";
        }
        $ticker = $repository->getTicker($testTickerId);
        return $ticker === null ? true : "Deleted ticker still found";
    }, $testsPassed, $testsFailed);

    echo PHP_EOL . "=== Test Results ===" . PHP_EOL;
    echo "Passed: {$testsPassed}" . PHP_EOL;
    echo "Failed: {$testsFailed}" . PHP_EOL;
    echo "Total:  " . ($testsPassed + $testsFailed) . PHP_EOL;

    if ($testsFailed === 0) {
        echo PHP_EOL . "✓ All tests passed!" . PHP_EOL;
        exit(0);
    } else {
        echo PHP_EOL . "✗ Some tests failed." . PHP_EOL;
        exit(1);
    }

} catch (Exception $e) {
    echo PHP_EOL . "✗ Test suite failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
