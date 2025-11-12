# Simple-Trader CLI Commands

This directory contains command-line interface (CLI) tools for running backtests and other operations.

## run-backtest.php

Execute backtests either from database configurations or directly with command-line parameters.

### Features

- **Two Execution Modes**: Run from database (by ID) or directly with parameters
- **Multiple Output Formats**: Human-readable console output or JSON
- **Optional Database Saving**: Choose whether to save run to database
- **Full Parameter Support**: All web UI features available via CLI
- **Optimization Support**: Run parameter optimization from command line

### Usage

#### Mode 1: Run from Database

Load an existing run configuration from the database and execute it:

```bash
php commands/run-backtest.php --run-id=<id> [options]
```

**Options:**
- `--run-id=<id>` - Database run ID to execute (required)
- `--format=human|json` - Output format (default: human)
- `--no-save` - Skip updating the run in database

**Examples:**

```bash
# Run backtest from database ID 1
php commands/run-backtest.php --run-id=1

# Run with JSON output
php commands/run-backtest.php --run-id=1 --format=json

# Run without updating database
php commands/run-backtest.php --run-id=1 --no-save
```

#### Mode 2: Direct Parameters

Execute a backtest directly with command-line parameters:

```bash
php commands/run-backtest.php --strategy=<name> --tickers=<ids> --start-date=<date> --end-date=<date> [options]
```

**Required Parameters:**
- `--strategy=<name>` - Strategy class name (e.g., TestStrategy)
- `--tickers=<ids>` - Comma-separated ticker IDs (e.g., 1,2,3)
- `--start-date=<date>` - Start date in YYYY-MM-DD format
- `--end-date=<date>` - End date in YYYY-MM-DD format

**Optional Parameters:**
- `--name=<name>` - Custom run name
- `--initial-capital=<amount>` - Initial capital (default: 10000)
- `--benchmark=<ticker-id>` - Benchmark ticker ID
- `--format=human|json` - Output format (default: human)
- `--no-save` - Skip saving to database
- `--param:<name>=<value>` - Strategy parameter (repeatable)
- `--optimize` - Enable optimization mode
- `--opt:<name>=<from>:<to>:<step>` - Optimization parameter range (repeatable)

**Examples:**

```bash
# Simple backtest
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1,2,3 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31

# Backtest without saving to database
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --no-save

# Backtest with custom parameters
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --param:threshold=0.05 \
  --param:window=14 \
  --param:stop_loss=0.02

# Backtest with benchmark
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1,2 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --benchmark=1 \
  --initial-capital=50000

# Optimization run
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --optimize \
  --opt:threshold=0.01:0.1:0.01 \
  --opt:window=5:20:1

# JSON output for scripting
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --format=json \
  --no-save
```

### Output Formats

#### Human-Readable (default)

Displays results in a clear, formatted console output:

```
=== Starting Backtest Run ===
Strategy: TestStrategy
Period: 2023-01-01 to 2023-12-31
Initial Capital: $10,000.00
Run ID: 42

Loaded tickers: AAPL, MSFT, GOOGL

Running backtest...
Backtest completed in 2.45s

=== Results ===
Net Profit: $1,234.56 (12.35%)
Total Transactions: 45
Profitable: 28 | Losing: 17
Win Rate: 62.22%
Profit Factor: 1.85
Max Drawdown: $456.78 (4.57%)
Average Win: $85.32
Average Loss: $45.67

=== Backtest Completed Successfully ===
Run saved to database with ID: 42
```

#### JSON Format

Outputs structured JSON data for programmatic processing:

```json
{
  "success": true,
  "run_id": 42,
  "execution_time": 2.45,
  "metrics": {
    "net_profit": 1234.56,
    "net_profit_percent": 12.35,
    "total_transactions": 45,
    "profitable_transactions": 28,
    "losing_transactions": 17,
    "profit_factor": 1.85,
    "max_drawdown_value": 456.78,
    "max_drawdown_percent": 4.57,
    "win_rate": 62.22,
    "average_win": 85.32,
    "average_loss": 45.67
  },
  "configuration": {
    "name": "Backtest 2024-01-15 10:30:00",
    "strategy": "TestStrategy",
    "tickers": [1, 2, 3],
    "start_date": "2023-01-01",
    "end_date": "2023-12-31",
    "initial_capital": 10000,
    "is_optimization": false
  }
}
```

### Strategy Parameters

Pass strategy-specific parameters using `--param:<name>=<value>`:

```bash
# Example: Moving Average Crossover strategy
php commands/run-backtest.php \
  --strategy=MACrossoverStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --param:fast_period=12 \
  --param:slow_period=26 \
  --param:signal_period=9
```

### Optimization

Enable optimization mode to test multiple parameter combinations:

```bash
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --optimize \
  --opt:threshold=0.01:0.1:0.01 \
  --opt:window=5:20:1
```

This will:
1. Test all combinations of threshold (0.01 to 0.1 in steps of 0.01)
2. And window (5 to 20 in steps of 1)
3. Display results for the best-performing combination

### Database Saving

By default, all runs are saved to the database. Use `--no-save` to skip database storage:

```bash
# Quick test without saving
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --no-save
```

**When to use `--no-save`:**
- Quick testing and experimentation
- Running many variations programmatically
- When you only need immediate results

**When to save (default):**
- Production backtests
- Results you want to review later in web UI
- Generating reports for documentation
- Tracking historical performance

### Exit Codes

- `0` - Success
- `1` - Error (invalid parameters, strategy not found, execution failure, etc.)

### Integration with Web UI

Runs created via CLI with database saving are fully accessible in the web UI:

1. View run details at `/runs/{id}`
2. See results, metrics, and charts
3. Download standalone HTML reports
4. Review execution logs

### Scripting Examples

#### Batch Testing Multiple Strategies

```bash
#!/bin/bash

strategies=("TestStrategy" "MACrossoverStrategy" "RSIStrategy")
tickers="1,2,3"
start="2023-01-01"
end="2023-12-31"

for strategy in "${strategies[@]}"; do
  echo "Testing $strategy..."
  php commands/run-backtest.php \
    --strategy=$strategy \
    --tickers=$tickers \
    --start-date=$start \
    --end-date=$end \
    --format=json > "results_${strategy}.json"
done
```

#### Automated Optimization

```bash
#!/bin/bash

# Run optimization and save JSON results
php commands/run-backtest.php \
  --strategy=TestStrategy \
  --tickers=1 \
  --start-date=2023-01-01 \
  --end-date=2023-12-31 \
  --optimize \
  --opt:threshold=0.01:0.1:0.01 \
  --format=json \
  --no-save > optimization_results.json

# Parse results and extract best parameters
best_params=$(jq -r '.configuration' optimization_results.json)
echo "Best parameters: $best_params"
```

### Troubleshooting

**Error: "Strategy not found"**
- Check that the strategy class exists in `src/`
- Verify the strategy name is correct (case-sensitive)
- Ensure the strategy extends `BaseStrategy`

**Error: "No asset data loaded"**
- Verify ticker IDs exist in database
- Check that quotes exist for the specified date range
- Use the web UI to fetch quotes if needed

**Error: "Missing required parameters"**
- Ensure all required parameters are provided
- Check parameter format (dates as YYYY-MM-DD, tickers as comma-separated IDs)

**Slow performance**
- Use shorter date ranges for testing
- Reduce the number of tickers
- For optimization, use larger step sizes initially

### Best Practices

1. **Test First**: Use `--no-save` when experimenting
2. **Name Your Runs**: Use `--name` for easy identification
3. **JSON for Automation**: Use `--format=json` for scripts
4. **Benchmark Comparisons**: Include `--benchmark` for context
5. **Optimize Wisely**: Start with broad ranges, then refine
6. **Document Parameters**: Save successful parameter combinations

