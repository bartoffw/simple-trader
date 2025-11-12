# Simple-Trader Database

This application uses two separate SQLite databases to keep data organized and performant.

## Database Structure

### 1. Tickers Database (`tickers.db`)
Stores ticker symbols, exchanges, data sources, and historical quote data.

**Tables:**
- `tickers` - Ticker metadata (symbol, exchange, source, enabled status)
- `quotes` - Historical OHLCV data for each ticker

### 2. Runs Database (`runs.db`)
Stores backtest execution runs, configurations, results, and logs.

**Tables:**
- `runs` - Backtest run configurations, status, results, and reports

## Why Two Databases?

**Performance & Organization:**
- Ticker/quote data remains compact and fast to query
- Large run logs and HTML reports don't bloat the tickers database
- Can backup/restore runs independently from ticker data
- Better separation of concerns

## Running Migrations

### Initial Setup (New Installation)

Run the main migration script to create both databases:

```bash
php database/migrate.php
```

This will:
1. Create `runs.db` and initialize the runs table
2. Create `tickers.db` and initialize tickers/quotes tables
3. Display success message with database paths

**Expected Output:**
```
=== Simple-Trader Database Migration ===

=== Runs Database ===
Database: /path/to/database/runs.db
Migrations: /path/to/database/runs-migrations

[1/2] Connecting to database...
✓ Connected successfully

[2/2] Running migrations...
  → Running 001_create_runs_table.sql... ✓

✓ Runs Database migrations completed!
Database: /path/to/database/runs.db

=== Tickers Database ===
Database: /path/to/database/tickers.db
Migrations: /path/to/database/migrations

[1/2] Connecting to database...
✓ Connected successfully

[2/2] Running migrations...
  → Running 001_create_tickers_table.sql... ✓
  → Running 002_add_source_to_tickers.sql... ✓
  → Running 003_create_quotes_table.sql... ✓
  → Running 005_drop_runs_table.sql... ✓

✓ Tickers Database migrations completed!
Database: /path/to/database/tickers.db

=== ✓ All Migrations Completed Successfully! ===

Databases created:
  - /path/to/database/runs.db
  - /path/to/database/tickers.db
```

### Upgrading (Existing Installation)

If you have an existing installation with runs in the tickers database:

```bash
# This will create runs.db and migrate tickers.db
php database/migrate.php
```

**Note:** Migration `005_drop_runs_table.sql` will drop the runs table from `tickers.db`. Any existing run data will be lost. This is by design for the database separation.

### Individual Database Migration (Advanced)

You can also migrate databases individually:

```bash
# Runs database only
php database/migrate-runs.php

# Tickers database only (legacy script, now handled by migrate.php)
# Not recommended - use migrate.php instead
```

## Migration Files

### Runs Migrations (`runs-migrations/`)
- `001_create_runs_table.sql` - Creates runs table with all columns

### Tickers Migrations (`migrations/`)
- `001_create_tickers_table.sql` - Creates tickers table
- `002_add_source_to_tickers.sql` - Adds source column
- `003_create_quotes_table.sql` - Creates quotes table
- `005_drop_runs_table.sql` - Removes runs table from tickers.db (separation)

## Troubleshooting

### Error: "no such table: runs"

This means the runs database hasn't been created. Run:

```bash
php database/migrate.php
```

### Error: "no such table: tickers" or "no such table: quotes"

This means the tickers database hasn't been created. Run:

```bash
php database/migrate.php
```

### Starting Fresh

To reset all databases and start over:

```bash
# Backup existing data first!
rm database/tickers.db database/runs.db

# Run migrations
php database/migrate.php
```

### Checking Database Status

Use SQLite command line to inspect databases:

```bash
# Check tickers database
sqlite3 database/tickers.db ".tables"
# Output: quotes  tickers

# Check runs database
sqlite3 database/runs.db ".tables"
# Output: runs

# View table schema
sqlite3 database/runs.db ".schema runs"
```

## Database Access in Code

### Web Application (`public/index.php`)

Both databases are registered in the DI container:

```php
// Tickers database
$container->set('db', function() use ($config) {
    return Database::getInstance($config['database']['tickers']);
});

// Runs database
$container->set('runsDb', function() use ($config) {
    return Database::getInstance($config['database']['runs']);
});

// Repositories
$container->set('tickerRepository', function($container) {
    return new TickerRepository($container->get('db'));
});

$container->set('runRepository', function($container) {
    return new RunRepository($container->get('runsDb'));
});
```

### CLI Scripts (`commands/`)

CLI scripts load both databases:

```php
$config = require __DIR__ . '/../config/config.php';

$tickersDb = Database::getInstance($config['database']['tickers']);
$runsDb = Database::getInstance($config['database']['runs']);

$runRepository = new RunRepository($runsDb);
$tickerRepository = new TickerRepository($tickersDb);
$quoteRepository = new QuoteRepository($tickersDb);
```

## Configuration (`config/config.php`)

Database paths are configured in the main config file:

```php
'database' => [
    'tickers' => __DIR__ . '/../database/tickers.db',
    'runs' => __DIR__ . '/../database/runs.db',
],
```

## Backup & Restore

### Backup

```bash
# Backup both databases
cp database/tickers.db database/backups/tickers-$(date +%Y%m%d).db
cp database/runs.db database/backups/runs-$(date +%Y%m%d).db

# Or use SQLite backup command
sqlite3 database/tickers.db ".backup database/backups/tickers-$(date +%Y%m%d).db"
sqlite3 database/runs.db ".backup database/backups/runs-$(date +%Y%m%d).db"
```

### Restore

```bash
# Restore from backup
cp database/backups/tickers-20240115.db database/tickers.db
cp database/backups/runs-20240115.db database/runs.db
```

## Best Practices

1. **Run migrations before starting the application**
2. **Backup databases before upgrading**
3. **Use the migration script** - Don't create tables manually
4. **Check migration output** - Ensure all migrations succeed
5. **Keep databases in database/ directory** - Don't move them unless updating config

## Adding New Migrations

### For Tickers Database

Create new file in `database/migrations/`:

```bash
# File: 006_add_new_column.sql
ALTER TABLE tickers ADD COLUMN new_column TEXT;
```

### For Runs Database

Create new file in `database/runs-migrations/`:

```bash
# File: 002_add_new_column.sql
ALTER TABLE runs ADD COLUMN new_column TEXT;
```

Then run `php database/migrate.php` to apply.

