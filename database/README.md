# Database Directory

This directory contains the SQLite database for ticker management and related scripts.

## Files

- **tickers.db** - SQLite database file (created after running migration)
- **migrate.php** - Database migration script
- **import-existing-tickers.php** - Import hardcoded tickers from investor.php
- **test-repository.php** - Comprehensive test suite for TickerRepository
- **migrations/** - SQL migration files

## Setup Instructions

### 1. Initialize the Database

Run the migration script to create the database and tables:

```bash
# Inside Docker container
php database/migrate.php

# Or via docker-compose
docker-compose exec -T trader php database/migrate.php
```

### 2. Import Existing Tickers

Import the hardcoded ticker(s) from `investor.php`:

```bash
# Inside Docker container
php database/import-existing-tickers.php

# Or via docker-compose
docker-compose exec -T trader php database/import-existing-tickers.php
```

### 3. Test the Repository (Optional)

Run the test suite to verify all CRUD operations:

```bash
# Inside Docker container
php database/test-repository.php

# Or via docker-compose
docker-compose exec -T trader php database/test-repository.php
```

## Database Schema

### Tickers Table

| Column      | Type         | Description                      |
|-------------|--------------|----------------------------------|
| id          | INTEGER      | Primary key (auto-increment)     |
| symbol      | VARCHAR(10)  | Ticker symbol (unique)           |
| exchange    | VARCHAR(10)  | Exchange code (e.g., XETR)       |
| csv_path    | VARCHAR(255) | Path to CSV data file            |
| enabled     | BOOLEAN      | Whether ticker is active         |
| created_at  | DATETIME     | Creation timestamp               |
| updated_at  | DATETIME     | Last update timestamp            |

### Ticker Audit Log Table

| Column      | Type         | Description                      |
|-------------|--------------|----------------------------------|
| id          | INTEGER      | Primary key (auto-increment)     |
| ticker_id   | INTEGER      | Foreign key to tickers table     |
| action      | VARCHAR(20)  | Action performed                 |
| old_values  | TEXT         | JSON of previous values          |
| new_values  | TEXT         | JSON of new values               |
| timestamp   | DATETIME     | When action occurred             |

## Usage Examples

### Get All Enabled Tickers (for investor.php)

```php
use SimpleTrader\Database\Database;
use SimpleTrader\Database\TickerRepository;

$database = Database::getInstance(__DIR__ . '/database/tickers.db');
$repository = new TickerRepository($database);

// Returns: ['IUSQ' => ['path' => '...', 'exchange' => 'XETR']]
$tickers = $repository->getEnabledTickers();
```

### Create a New Ticker

```php
$tickerId = $repository->createTicker([
    'symbol' => 'AAPL',
    'exchange' => 'NASDAQ',
    'csv_path' => __DIR__ . '/AAPL.csv',
    'enabled' => true
]);
```

### Update a Ticker

```php
$repository->updateTicker($tickerId, [
    'exchange' => 'NYSE',
    'csv_path' => '/new/path/to/AAPL.csv'
]);
```

### Toggle Enabled Status

```php
$newStatus = $repository->toggleEnabled($tickerId);
// Returns: true if enabled, false if disabled
```

### Delete a Ticker

```php
$repository->deleteTicker($tickerId);
```

### Get Statistics

```php
$stats = $repository->getStatistics();
// Returns: ['total' => 5, 'enabled' => 4, 'disabled' => 1]
```

## Notes

- The database file (`tickers.db`) is excluded from version control via `.gitignore`
- All ticker symbols are automatically converted to uppercase
- Foreign key constraints are enabled
- Audit logging is automatic for all create/update/delete/toggle operations
- Path traversal attempts in CSV paths are rejected by validation
