#!/usr/bin/env php
<?php

/**
 * CLI Tool: Fetch Quotes
 *
 * Fetches quotation data for a specific ticker from its configured data source.
 *
 * Usage:
 *   php commands/fetch-quotes.php <ticker-id> [bar-count]
 *
 * Examples:
 *   php commands/fetch-quotes.php 1
 *   php commands/fetch-quotes.php 1 100
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use SimpleTrader\Commands\FetchQuotesCommand;

// Create console application
$application = new Application('Simple Trader CLI', '1.0.0');

// Register command
$command = new FetchQuotesCommand();
$application->add($command);

// Set as default command so users don't need to specify command name
$application->setDefaultCommand($command->getName(), true);

// Run the application
try {
    $application->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
