# Simple-Trader User Guide

## Table of Contents

1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Quick Start](#quick-start)
4. [Creating Your First Strategy](#creating-your-first-strategy)
5. [Backtesting Strategies](#backtesting-strategies)
6. [Optimizing Strategy Parameters](#optimizing-strategy-parameters)
7. [Live Trading](#live-trading)
8. [Understanding Reports](#understanding-reports)
9. [Data Management](#data-management)
10. [Email Notifications](#email-notifications)
11. [Troubleshooting](#troubleshooting)
12. [FAQ](#faq)

## Introduction

Simple-Trader is a powerful PHP library that allows you to:

- **Backtest** trading strategies against historical market data
- **Optimize** strategy parameters to find the best settings
- **Execute** live trading with real-time market data
- **Generate** comprehensive performance reports with charts
- **Receive** email notifications when trades are executed

Whether you're a quantitative trader, algorithmic trading enthusiast, or financial developer, Simple-Trader provides an easy-to-use framework for testing and executing your trading ideas.

### What You Can Do

- Test strategies on years of historical data in seconds
- Compare multiple parameter combinations automatically
- Track key metrics: profit/loss, win rate, Sharpe ratio, drawdown
- Run strategies on multiple assets simultaneously
- Get notified by email when positions open or close

### What You Need to Know

- Basic PHP programming
- Basic understanding of trading concepts (long/short positions, technical indicators)
- How to read CSV files with market data

## Installation

### Option 1: Using Docker (Recommended)

**Prerequisites:**
- Docker Desktop installed on your computer
- Basic command line knowledge

**Steps:**

1. **Download the project:**
```bash
git clone https://github.com/bartoffw/simple-trader.git
cd simple-trader
```

2. **Start the Docker container:**
```bash
docker-compose up -d
```

3. **Install PHP dependencies:**
```bash
docker-compose exec trader composer install
```

4. **Verify installation:**
```bash
docker-compose exec trader php --version
```
You should see PHP 8.3 or higher.

### Option 2: Local Installation

**Prerequisites:**
- PHP 8.3 or higher
- Composer package manager
- Required PHP extensions: bcmath, gd, sqlite3
- PECL trader extension

**Steps:**

1. **Install PHP and extensions** (Ubuntu/Debian example):
```bash
sudo apt-get update
sudo apt-get install php8.3 php8.3-cli php8.3-bcmath php8.3-gd php8.3-sqlite3
sudo pecl install trader
```

2. **Install Composer:**
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

3. **Download and install dependencies:**
```bash
git clone https://github.com/bartoffw/simple-trader.git
cd simple-trader
composer install
```

4. **Verify installation:**
```bash
php --version
php -m | grep trader  # Should show "trader"
```

## Quick Start

Let's run your first backtest in 5 minutes!

### Step 1: Prepare Your Data

You need historical market data in CSV format. The CSV should have these columns:
```
date,open,high,low,close,volume
2020-01-02,300.35,300.92,298.87,300.35,32800000
2020-01-03,297.15,300.58,297.13,297.43,36600000
```

Save this as `data/AAPL.csv` (create the `data` folder if it doesn't exist).

> **Where to get data?** You can download historical data from Yahoo Finance, TradingView, or other financial data providers. Export as CSV.

### Step 2: Run the Example Strategy

```bash
# If using Docker:
docker-compose exec trader php runner.php

# If using local installation:
php runner.php
```

This will:
1. Load the CSV data
2. Run the test strategy
3. Generate an HTML report
4. Display basic statistics

### Step 3: View Your Report

Open the generated `report.html` file in your web browser. You'll see:

- **Capital curve chart**: How your capital grew/shrunk over time
- **Drawdown chart**: Maximum loss from peak
- **Performance statistics**: Win rate, profit factor, Sharpe ratio
- **Trade log**: Every trade with entry/exit prices and profit

Congratulations! You've just completed your first backtest! 🎉

## Creating Your First Strategy

Let's create a simple moving average crossover strategy.

### Step 1: Create Your Strategy File

Create a new file `src/MyFirstStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace SimpleTrader;

use SimpleTrader\Helpers\Side;
use SimpleTrader\Helpers\QuantityType;

class MyFirstStrategy extends BaseStrategy
{
    // Strategy parameters
    protected int $fastMA = 10;    // Fast moving average period
    protected int $slowMA = 30;    // Slow moving average period

    // This method runs after each bar closes
    public function onClose(Event $event): void
    {
        // Get closing prices
        $closePrices = $this->assets->getData($this->ticker)['close'];

        // Calculate moving averages
        $fast = trader_sma($closePrices, $this->fastMA);
        $slow = trader_sma($closePrices, $this->slowMA);

        // Get current and previous values
        $currentFast = $fast[$this->currentBar];
        $currentSlow = $slow[$this->currentBar];
        $previousFast = $fast[$this->currentBar - 1];
        $previousSlow = $slow[$this->currentBar - 1];

        // Check if we can calculate (need enough data)
        if (is_nan($currentFast) || is_nan($currentSlow)) {
            return; // Not enough data yet
        }

        // BUY SIGNAL: Fast MA crosses above Slow MA
        if ($this->position === null &&                    // No position open
            $previousFast <= $previousSlow &&              // Was below
            $currentFast > $currentSlow) {                 // Now above

            $this->entry(
                side: Side::Long,
                quantity: 100,                              // Use 100% of capital
                quantityType: QuantityType::Percent,
                comment: "Golden Cross - Buy Signal"
            );
        }

        // SELL SIGNAL: Fast MA crosses below Slow MA
        if ($this->position !== null &&                    // Position is open
            $previousFast >= $previousSlow &&              // Was above
            $currentFast < $currentSlow) {                 // Now below

            $this->close(comment: "Death Cross - Sell Signal");
        }
    }
}
```

### Step 2: Create a Test Script

Create `test_my_strategy.php` in the root directory:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\{Backtester, Assets, MyFirstStrategy};
use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loggers\{Console, Level};
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Reporting\HtmlReport;

// Create backtester with your strategy
$backtester = new Backtester(
    strategy: MyFirstStrategy::class,
    capital: 10000,                      // Start with $10,000
    logger: new Console(Level::Info)     // Show info messages
);

// Load your data
$assets = new Assets('AAPL', Resolution::Daily);
$assets->addSource(new Csv(__DIR__ . '/data/AAPL.csv'));

// Run backtest
echo "Running backtest...\n";
$result = $backtester->run(
    assets: $assets,
    rangeStart: '2020-01-01',
    rangeEnd: '2023-12-31'
);

// Get statistics
$stats = $result->getStatistics();

// Display results
echo "\n=== BACKTEST RESULTS ===\n";
echo "Initial Capital: $10,000\n";
echo "Final Capital: $" . number_format($stats['currentCapital'], 2) . "\n";
echo "Net Profit: $" . number_format($stats['netProfit'], 2) . "\n";
echo "Return: " . number_format($stats['return'], 2) . "%\n";
echo "Total Trades: " . $stats['totalTrades'] . "\n";
echo "Win Rate: " . number_format($stats['winRate'], 2) . "%\n";
echo "Profit Factor: " . number_format($stats['profitFactor'], 2) . "\n";
echo "Max Drawdown: " . number_format($stats['maxDrawdown'], 2) . "%\n";
echo "Sharpe Ratio: " . number_format($stats['sharpeRatio'], 2) . "\n";

// Generate HTML report
$report = new HtmlReport($result, $backtester);
file_put_contents(__DIR__ . '/my_strategy_report.html', $report->generate());
echo "\nReport saved to: my_strategy_report.html\n";
```

### Step 3: Run Your Strategy

```bash
# If using Docker:
docker-compose exec trader php test_my_strategy.php

# If using local installation:
php test_my_strategy.php
```

You'll see output like:
```
Running backtest...
Initial Capital: $10,000
Final Capital: $12,456.78
Net Profit: $2,456.78
Return: 24.57%
Total Trades: 15
Win Rate: 60.00%
Profit Factor: 1.85
Max Drawdown: -8.23%
Sharpe Ratio: 1.42

Report saved to: my_strategy_report.html
```

### Understanding the Strategy

**Entry Logic (Buy):**
- Wait for fast MA to cross above slow MA
- This is called a "Golden Cross"
- Signals potential upward trend

**Exit Logic (Sell):**
- Wait for fast MA to cross below slow MA
- This is called a "Death Cross"
- Signals potential downward trend

**Position Sizing:**
- Uses 100% of available capital
- Only one position open at a time

## Backtesting Strategies

### Basic Backtest

A backtest simulates your strategy on historical data to see how it would have performed.

**Key Concepts:**

1. **Capital**: The starting amount of money (e.g., $10,000)
2. **Date Range**: The period to test (e.g., 2020-2023)
3. **Assets**: The ticker/stock to trade (e.g., AAPL)
4. **Resolution**: The timeframe (Daily, Hourly, etc.)

**Example:**

```php
$backtester = new Backtester(
    strategy: MyFirstStrategy::class,
    capital: 50000,                  // Start with $50,000
    logger: new Console(Level::Info)
);

$assets = new Assets('TSLA', Resolution::Daily);
$assets->addSource(new Csv('./data/TSLA.csv'));

$result = $backtester->run(
    assets: $assets,
    rangeStart: '2021-01-01',
    rangeEnd: '2023-12-31'
);
```

### Testing Multiple Assets

You can backtest a strategy that trades multiple stocks simultaneously:

```php
// Create assets with multiple tickers
$assets = new Assets('AAPL', Resolution::Daily);
$assets->addSource(new Csv('./data/AAPL.csv'));
$assets->addData('GOOGL', new Csv('./data/GOOGL.csv'));
$assets->addData('MSFT', new Csv('./data/MSFT.csv'));

// Your strategy can access all tickers
$result = $backtester->run($assets);
```

In your strategy, access different tickers:
```php
$applePrice = $this->assets->getValue('AAPL', $this->currentBar);
$googlePrice = $this->assets->getValue('GOOGL', $this->currentBar);
```

### Changing Strategy Parameters

You can override default parameters without modifying the strategy code:

```php
$backtester = new Backtester(
    strategy: MyFirstStrategy::class,
    capital: 10000
);

// Override parameters
$backtester->overrideParam('fastMA', 5);   // Change from 10 to 5
$backtester->overrideParam('slowMA', 20);  // Change from 30 to 20

$result = $backtester->run($assets);
```

### Understanding Statistics

After a backtest, you get these key metrics:

| Metric | Description | Good Value |
|--------|-------------|------------|
| **Net Profit** | Total profit/loss | Positive |
| **Return %** | Percentage gain on capital | > 0% |
| **Total Trades** | Number of trades executed | Varies |
| **Win Rate** | Percentage of winning trades | > 50% |
| **Profit Factor** | Gross profit ÷ Gross loss | > 1.5 |
| **Max Drawdown** | Largest peak-to-valley loss | < 20% |
| **Sharpe Ratio** | Risk-adjusted returns | > 1.0 |
| **Average Trade** | Average profit per trade | Positive |

**Interpreting Results:**

- **Win Rate 60%**: 6 out of 10 trades are profitable
- **Profit Factor 2.0**: You make $2 for every $1 lost
- **Max Drawdown 15%**: Worst loss from peak was 15%
- **Sharpe Ratio 1.5**: Good risk-adjusted returns

## Optimizing Strategy Parameters

Parameter optimization tests different combinations of settings to find the best ones.

### Why Optimize?

Your strategy might work better with different settings. Instead of manually testing each combination, optimization does it automatically.

### Example Optimization

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\{Backtester, Assets, MyFirstStrategy};
use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loggers\{Console, Level};
use SimpleTrader\Helpers\{Resolution, OptimizationParam};
use SimpleTrader\Reporting\HtmlReport;

// Create backtester
$backtester = new Backtester(
    strategy: MyFirstStrategy::class,
    capital: 10000,
    logger: new Console(Level::Info)
);

// Define parameters to test
$backtester->addOptimizationParam(
    new OptimizationParam(
        name: 'fastMA',
        start: 5,          // Start at 5
        end: 20,           // End at 20
        step: 5            // Test 5, 10, 15, 20
    )
);

$backtester->addOptimizationParam(
    new OptimizationParam(
        name: 'slowMA',
        start: 20,         // Start at 20
        end: 50,           // End at 50
        step: 10           // Test 20, 30, 40, 50
    )
);

// Load data
$assets = new Assets('AAPL', Resolution::Daily);
$assets->addSource(new Csv('./data/AAPL.csv'));

// Run optimization (tests all combinations)
echo "Running optimization...\n";
echo "This will test 4 x 4 = 16 combinations\n";

$results = $backtester->runOptimization(
    assets: $assets,
    rangeStart: '2020-01-01',
    rangeEnd: '2023-12-31'
);

// Display top 5 results
echo "\n=== TOP 5 RESULTS ===\n";
$count = 0;
foreach ($results as $result) {
    $stats = $result->getStatistics();
    echo "\n#" . (++$count) . "\n";
    echo "Parameters: fastMA={$result->fastMA}, slowMA={$result->slowMA}\n";
    echo "Net Profit: $" . number_format($stats['netProfit'], 2) . "\n";
    echo "Win Rate: " . number_format($stats['winRate'], 2) . "%\n";
    echo "Sharpe Ratio: " . number_format($stats['sharpeRatio'], 2) . "\n";

    if ($count >= 5) break;
}

// Generate comparison report
$report = new HtmlReport($results, $backtester);
file_put_contents(__DIR__ . '/optimization_report.html', $report->generate());
echo "\nComparison report saved to: optimization_report.html\n";
```

### Optimization Tips

**Do:**
- Use reasonable ranges (don't test 1-1000)
- Use appropriate step sizes (5-10 for periods)
- Test on out-of-sample data afterward
- Look for robust parameters (work across multiple results)

**Don't:**
- Over-optimize (curve fitting)
- Use too many parameters (exponential combinations)
- Assume the best historical result will be best in the future
- Test on too short time periods

### Avoiding Overfitting

Overfitting means your parameters work great on historical data but fail on new data.

**Prevention strategies:**

1. **Walk-forward analysis**: Optimize on one period, test on the next
2. **Out-of-sample testing**: Keep some data hidden during optimization
3. **Robust parameters**: Choose settings that work well across multiple tests
4. **Simple strategies**: Complex strategies with many parameters overfit more

**Example Walk-Forward:**

```php
// Optimize on 2020-2021
$results = $backtester->runOptimization($assets, '2020-01-01', '2021-12-31');
$best = $results[0];

// Test on 2022-2023 (out-of-sample)
$backtester->overrideParam('fastMA', $best->fastMA);
$backtester->overrideParam('slowMA', $best->slowMA);
$testResult = $backtester->run($assets, '2022-01-01', '2023-12-31');
```

## Live Trading

Live trading executes your strategy with real-time market data.

> **⚠️ IMPORTANT**: Live trading involves real financial risk. Always test thoroughly with paper trading first!

### Setting Up Live Trading

**Step 1: Configure Email Notifications**

Copy the example environment file:
```bash
cp .env.example .env
```

Edit `.env` with your SMTP settings:
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
FROM_EMAIL=your-email@gmail.com
TO_EMAIL=recipient@example.com
```

> For Gmail, you need to create an [App Password](https://support.google.com/accounts/answer/185833).

**Step 2: Create Live Trading Script**

Create `my_live_trader.php`:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\{MyFirstStrategy, Assets};
use SimpleTrader\Investor\{Investor, Investment, EmailNotifier};
use SimpleTrader\Loaders\TradingViewSource;
use SimpleTrader\Helpers\Resolution;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// State file for persistence
$stateFile = __DIR__ . '/live_trading_state.json';

// Create investor with email notifications
$investor = new Investor(
    new EmailNotifier(
        host: $_ENV['SMTP_HOST'],
        port: (int)$_ENV['SMTP_PORT'],
        username: $_ENV['SMTP_USER'],
        password: $_ENV['SMTP_PASS'],
        fromEmail: $_ENV['FROM_EMAIL'],
        toEmail: $_ENV['TO_EMAIL']
    )
);

// Load previous state if exists
if (file_exists($stateFile)) {
    echo "Loading previous state...\n";
    $investor->loadState($stateFile);
} else {
    // First run - create new investment
    echo "Initializing new investment...\n";

    $investment = new Investment(
        strategy: MyFirstStrategy::class,
        source: new TradingViewSource('AAPL', Resolution::Daily),
        capital: 10000
    );

    $investor->addInvestment($investment);
}

// Update market data
echo "Fetching latest market data...\n";
$investor->updateData();

// Check if market is open (example - customize for your timezone)
$hour = (int)date('H');
$isMarketHours = ($hour >= 9 && $hour < 16);

if ($isMarketHours) {
    echo "Market is open - triggering OnOpen event\n";
    $investor->onOpen();
} else {
    echo "Market is closed - triggering OnClose event\n";
    $investor->onClose();
}

// Save state for next run
echo "Saving state...\n";
$investor->saveState($stateFile);

echo "Done!\n";
```

**Step 3: Schedule Regular Execution**

Use cron (Linux/Mac) or Task Scheduler (Windows) to run your script regularly.

**Cron example** (run every hour during market hours):
```bash
crontab -e
```

Add:
```
0 9-16 * * 1-5 cd /path/to/simple-trader && docker-compose exec -T trader php my_live_trader.php
```

This runs:
- Every hour (0 minutes past the hour)
- Between 9 AM and 4 PM
- Monday through Friday

### Live Trading Best Practices

1. **Start with paper trading**: Test with mock data first
2. **Small capital**: Start with a small amount
3. **Monitor closely**: Check email notifications and logs
4. **Set limits**: Use stop losses and position limits
5. **Keep backups**: Regularly backup your state file
6. **Test connectivity**: Ensure reliable data source connection

### State Management

The state file (`live_trading_state.json`) stores:
- Open positions
- Trade history
- Current capital
- Asset data

**Viewing state:**
```bash
cat live_trading_state.json | jq
```

**Resetting state:**
```bash
rm live_trading_state.json
```
> ⚠️ This will close all virtual positions!

## Understanding Reports

Reports provide visual and tabular analysis of your backtest results.

### Report Sections

**1. Capital Curve**
- Shows how your capital changed over time
- X-axis: Dates
- Y-axis: Capital amount
- Green line: Growing capital
- Red line: Declining capital

**2. Drawdown Chart**
- Shows losses from peak capital
- Identifies risk periods
- Lower is worse (more loss)

**3. Performance Statistics**
- Summary of key metrics
- Compare against benchmarks
- Identify strengths and weaknesses

**4. Trade Log Table**
- Every trade with details
- Entry/exit dates and prices
- Profit/loss per trade
- Click to sort by columns

**5. Parameter Information**
- Strategy settings used
- Date range tested
- Initial capital

### Comparing Multiple Results

Optimization reports show multiple strategies side-by-side:

- **Tabs**: Click to switch between results
- **Sorted**: Best result first (by net profit)
- **Colors**: Color-coded for easy comparison

### Exporting Results

**Save charts as images:**
Right-click on chart → Save Image As

**Export trade log:**
Copy table from HTML or access `$result->getClosedPositions()` in PHP:

```php
$positions = $result->getClosedPositions();

$csv = fopen('trades.csv', 'w');
fputcsv($csv, ['Entry Date', 'Exit Date', 'Side', 'Entry Price', 'Exit Price', 'Profit']);

foreach ($positions as $position) {
    fputcsv($csv, [
        $position->entryDate->format('Y-m-d'),
        $position->exitDate->format('Y-m-d'),
        $position->side->name,
        $position->entryPrice,
        $position->exitPrice,
        $position->getProfitLoss()
    ]);
}

fclose($csv);
```

## Data Management

### CSV Data Format

Your CSV files must have these columns (in this order):

```
date,open,high,low,close,volume
```

**Example:**
```csv
date,open,high,low,close,volume
2020-01-02,296.24,300.60,295.26,300.35,32800000
2020-01-03,297.15,300.58,297.13,297.43,36600000
2020-01-06,293.79,299.96,292.28,299.80,29596000
```

**Requirements:**
- Date format: `YYYY-MM-DD`
- Numbers: No commas or dollar signs
- Header row: Required
- Order: Chronological (oldest first)

### Getting Historical Data

**Yahoo Finance:**
1. Go to https://finance.yahoo.com
2. Search for your ticker (e.g., AAPL)
3. Click "Historical Data"
4. Select date range
5. Click "Download"
6. Save as CSV in your `data/` folder

**TradingView:**
1. Open chart for your symbol
2. Right-click chart → "Export chart data"
3. Save as CSV
4. May need to reformat columns

### Importing to SQLite

For faster access to large datasets, import to SQLite:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Assets;
use SimpleTrader\Loaders\Csv;
use SimpleTrader\Helpers\Resolution;

// Load from CSV
$assets = new Assets('AAPL', Resolution::Daily);
$assets->addSource(new Csv('./data/AAPL.csv'));

// Get data
$data = $assets->getData('AAPL');

// Save to SQLite
$db = new SQLite3('./data/market_data.db');

$db->exec('
    CREATE TABLE IF NOT EXISTS ohlc (
        ticker TEXT,
        date TEXT,
        open REAL,
        high REAL,
        low REAL,
        close REAL,
        volume INTEGER,
        PRIMARY KEY (ticker, date)
    )
');

$stmt = $db->prepare('
    INSERT OR REPLACE INTO ohlc (ticker, date, open, high, low, close, volume)
    VALUES (:ticker, :date, :open, :high, :low, :close, :volume)
');

foreach ($data as $row) {
    $stmt->bindValue(':ticker', 'AAPL');
    $stmt->bindValue(':date', $row['date']->format('Y-m-d'));
    $stmt->bindValue(':open', $row['open']);
    $stmt->bindValue(':high', $row['high']);
    $stmt->bindValue(':low', $row['low']);
    $stmt->bindValue(':close', $row['close']);
    $stmt->bindValue(':volume', $row['volume']);
    $stmt->execute();
}

echo "Imported " . count($data) . " rows to database\n";
```

## Email Notifications

Email notifications keep you informed of trade activity.

### What Gets Notified

- **Position opened**: When strategy enters a trade
- **Position closed**: When strategy exits a trade
- **Errors**: If strategy encounters an error
- **Daily summary**: Overview of all active strategies

### Email Configuration

**Gmail Setup:**

1. Enable 2-factor authentication
2. Generate App Password:
   - Google Account → Security → 2-Step Verification → App passwords
   - Select "Mail" and "Other"
   - Copy the 16-character password
3. Use in `.env`:
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-16-char-app-password
```

**Other Email Providers:**

| Provider | SMTP Host | Port |
|----------|-----------|------|
| Gmail | smtp.gmail.com | 587 |
| Outlook | smtp-mail.outlook.com | 587 |
| Yahoo | smtp.mail.yahoo.com | 587 |
| AOL | smtp.aol.com | 587 |

### Testing Notifications

Test without live trading:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\Investor\EmailNotifier;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$notifier = new EmailNotifier(
    host: $_ENV['SMTP_HOST'],
    port: (int)$_ENV['SMTP_PORT'],
    username: $_ENV['SMTP_USER'],
    password: $_ENV['SMTP_PASS'],
    fromEmail: $_ENV['FROM_EMAIL'],
    toEmail: $_ENV['TO_EMAIL']
);

// Send test email
$notifier->sendTestEmail();
echo "Test email sent! Check your inbox.\n";
```

### Customizing Notifications

Notifications include:
- Strategy name
- Ticker symbol
- Position side (Long/Short)
- Entry/Exit price
- Profit/Loss
- Trade comment

## Troubleshooting

### Common Issues

**1. "Class 'trader_sma' not found"**

The trader extension is not installed.

**Solution:**
```bash
sudo pecl install trader
# Then add to php.ini: extension=trader.so
```

**2. "CSV file not found"**

The data file path is incorrect.

**Solution:**
- Use absolute paths: `__DIR__ . '/data/AAPL.csv'`
- Check file exists: `ls data/AAPL.csv`
- Verify file permissions

**3. "NaN values in indicators"**

Not enough data for indicator calculation.

**Solution:**
```php
if (is_nan($indicator[$this->currentBar])) {
    return; // Skip this bar
}
```

**4. "Memory limit exceeded"**

Large datasets use too much memory.

**Solution:**
- Increase memory in `docker/php.ini`: `memory_limit = 4G`
- Use shorter date ranges
- Reduce optimization parameter combinations

**5. "Email not sending"**

SMTP configuration is incorrect.

**Solution:**
- Verify SMTP credentials
- Check firewall/ports
- Use App Passwords for Gmail
- Test with simple PHP mailer script

**6. "Positions not opening"**

Strategy logic may have issues.

**Solution:**
- Add debug logging:
```php
echo "Current bar: {$this->currentBar}\n";
echo "Fast MA: {$fast[$this->currentBar]}\n";
echo "Slow MA: {$slow[$this->currentBar]}\n";
```
- Check indicator values
- Verify entry conditions are being met

### Debug Mode

Enable detailed logging:

```php
use SimpleTrader\Loggers\{Console, Level};

$backtester = new Backtester(
    strategy: MyFirstStrategy::class,
    capital: 10000,
    logger: new Console(Level::Debug)  // Show everything
);
```

### Getting Help

If you're stuck:

1. Check the error message carefully
2. Review the generated HTML report
3. Add debug output to your strategy
4. Test with smaller date ranges
5. Verify your CSV data is valid
6. Check PHP error logs

## FAQ

**Q: What timeframes are supported?**

A: Tick, Minute, Hourly, Daily, Weekly, Monthly (defined in `Resolution` enum)

**Q: Can I short sell?**

A: Yes, use `Side::Short` when calling `entry()`

**Q: Can I trade multiple positions simultaneously?**

A: The base framework supports one position at a time per strategy. To trade multiple positions, create multiple investment instances.

**Q: What technical indicators are available?**

A: All PECL trader functions: https://www.php.net/manual/en/book.trader.php
- Moving averages (SMA, EMA, WMA)
- Oscillators (RSI, Stochastic, MACD)
- Volatility (Bollinger Bands, ATR)
- And many more!

**Q: Can I use this for crypto trading?**

A: Yes, as long as you have OHLC data in CSV format or a compatible data source.

**Q: How accurate is backtesting?**

A: Backtesting assumes:
- You can trade at close prices (no slippage)
- Infinite liquidity
- No transaction costs
- Perfect execution

Real trading will have slippage, fees, and execution delays. Consider adding these to your strategy logic.

**Q: Is this suitable for high-frequency trading?**

A: No, this framework is designed for daily/hourly strategies. For HFT, you need lower-level access and optimized languages.

**Q: Can I backtest with multiple assets?**

A: Yes, add multiple tickers to `Assets`:
```php
$assets = new Assets('AAPL', Resolution::Daily);
$assets->addData('GOOGL', new Csv('./data/GOOGL.csv'));
$assets->addData('MSFT', new Csv('./data/MSFT.csv'));
```

**Q: How do I calculate stop losses?**

A: In your strategy:
```php
if ($this->position !== null) {
    $entryPrice = $this->position->entryPrice;
    $currentPrice = $this->assets->getValue($this->ticker, $this->currentBar);
    $stopLoss = $entryPrice * 0.95; // 5% stop loss

    if ($currentPrice <= $stopLoss) {
        $this->close(comment: "Stop loss hit");
    }
}
```

**Q: Can I get bid/ask prices?**

A: The framework uses close prices. For bid/ask, you'd need to customize your data loader.

**Q: What license is this?**

A: GPL-3.0 (GNU General Public License v3.0)

---

## Next Steps

Now that you understand the basics:

1. **Create your own strategy** based on your trading ideas
2. **Backtest thoroughly** on multiple time periods
3. **Optimize carefully** to avoid overfitting
4. **Paper trade** before using real money
5. **Start small** with live trading
6. **Monitor and adjust** based on real results

For more advanced topics, see the [Developer Guide](DEVELOPER_GUIDE.md).

**Happy Trading!** 📈
