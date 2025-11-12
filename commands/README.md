# Simple Trader CLI Commands

This directory contains command-line tools for managing Simple Trader operations.

## Available Commands

### Fetch Quotes

Fetches quotation data for a specific ticker from its configured data source.

**Usage:**
```bash
php commands/fetch-quotes.php <ticker-id> [bar-count]
```

**Arguments:**
- `ticker-id` (required): The ID of the ticker to fetch quotes for
- `bar-count` (optional): Number of bars to fetch. If not specified, automatically calculates missing days.

**Examples:**
```bash
# Fetch quotes for ticker with ID 1 (auto-calculates missing days)
php commands/fetch-quotes.php 1

# Fetch last 100 bars for ticker with ID 1
php commands/fetch-quotes.php 1 100

# Get help
php commands/fetch-quotes.php quotes:fetch --help
```

**What it does:**
1. Loads ticker configuration from database
2. Determines how many bars to fetch (or uses provided bar-count)
3. Connects to the configured data source
4. Fetches OHLCV (Open, High, Low, Close, Volume) data
5. Stores quotes in the database
6. Displays summary of fetched data

## Requirements

- PHP 8.3 or higher
- Composer dependencies installed (run `composer install`)
- SQLite database configured

## Framework

All CLI commands are built using [Symfony Console](https://symfony.com/doc/current/components/console.html), which provides:
- Argument and option parsing
- Formatted output
- Interactive helpers
- Error handling

## Adding New Commands

1. Create a new command class in `src/Commands/` extending `Symfony\Component\Console\Command\Command`
2. Create an entry point script in `commands/`
3. Register the command in the entry point script
4. Document it in this README

**Example:**
```php
// src/Commands/MyCommand.php
namespace SimpleTrader\Commands;

use Symfony\Component\Console\Command\Command;

class MyCommand extends Command
{
    protected static $defaultName = 'my:command';
    // ... implementation
}
```

```php
// commands/my-command.php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use SimpleTrader\Commands\MyCommand;

$application = new Application();
$application->add(new MyCommand());
$application->run();
```
