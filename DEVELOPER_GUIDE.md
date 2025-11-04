# Simple-Trader Developer Guide

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Development Setup](#development-setup)
4. [Core Components](#core-components)
5. [Creating Custom Strategies](#creating-custom-strategies)
6. [Creating Custom Data Loaders](#creating-custom-data-loaders)
7. [Creating Custom Notifiers](#creating-custom-notifiers)
8. [Testing Strategies](#testing-strategies)
9. [Extending the Framework](#extending-the-framework)
10. [Code Style and Best Practices](#code-style-and-best-practices)

## Overview

Simple-Trader is a PHP-based trading strategy backtesting and live trading execution library. The framework is designed with modularity and extensibility in mind, allowing developers to:

- Create custom trading strategies
- Backtest strategies against historical data
- Optimize strategy parameters
- Execute live trading with real-time data
- Receive notifications on trade events
- Generate comprehensive performance reports

### Key Technologies

- **PHP 8.3+** - Modern PHP with enums, typed properties, and match expressions
- **Carbon 3.3** - Date/time manipulation
- **MammothPHP WoollyM** - DataFrame library for data operations
- **JpGraph 10.4** - Chart generation
- **PHPMailer 6.9** - Email notifications
- **PECL Trader Extension** - Technical analysis indicators
- **SQLite 3** - Data persistence

## Architecture

### Design Principles

1. **Event-Driven**: Strategies respond to market events (OnOpen, OnClose)
2. **Interface-Based**: Pluggable components via interfaces (NotifierInterface, SourceInterface, LoggerInterface)
3. **Type-Safe**: Extensive use of enums and strong typing
4. **Data-First**: Built on DataFrame for efficient data manipulation

### Layer Structure

```
┌─────────────────────────────────────────┐
│         Entry Points Layer              │
│  (runner.php, investor.php)             │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│         Strategy Layer                  │
│  (BaseStrategy, TestStrategy)           │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│         Core Layer                      │
│  (Backtester, Assets, Investor)         │
└────────────────┬────────────────────────┘
                 │
┌────────────────▼────────────────────────┐
│         Data Layer                      │
│  (Loaders, Sources)                     │
└─────────────────────────────────────────┘
```

## Development Setup

### Prerequisites

- Docker and Docker Compose
- PHP 8.3+ (if running locally)
- Composer

### Quick Start with Docker

1. **Clone the repository**
```bash
git clone <repository-url>
cd simple-trader
```

2. **Copy environment file**
```bash
cp .env.example .env
```

3. **Configure SMTP settings** (for email notifications)
Edit `.env` and set:
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
FROM_EMAIL=your-email@gmail.com
TO_EMAIL=recipient@example.com
```

4. **Build and start containers**
```bash
docker-compose up -d
```

5. **Install dependencies**
```bash
docker-compose exec trader composer install
```

6. **Run a test backtest**
```bash
docker-compose exec trader php runner.php
```

### Local Development Setup

If you prefer to run locally without Docker:

1. **Install PHP 8.3+** with required extensions:
```bash
# Ubuntu/Debian
sudo apt-get install php8.3 php8.3-cli php8.3-bcmath php8.3-gd php8.3-sqlite3
sudo pecl install trader
```

2. **Install Composer**
```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

3. **Install dependencies**
```bash
composer install
```

4. **Run scripts**
```bash
php runner.php
```

## Core Components

### 1. BaseStrategy (`src/BaseStrategy.php`)

The abstract base class for all trading strategies. Provides core functionality for position management, trade tracking, and statistics.

**Key Properties:**
```php
protected float $capital;           // Initial capital
protected float $currentCapital;    // Available capital
protected ?Position $position;      // Current open position
protected array $closedPositions;   // Trade history
protected int $currentBar;          // Current bar index
protected Assets $assets;           // Market data
```

**Key Methods:**

| Method | Purpose | Override Required |
|--------|---------|-------------------|
| `onOpen(Event $event)` | Called before market opens | Optional |
| `onClose(Event $event)` | Called after market closes | Optional |
| `entry()` | Open a position | Use in strategy |
| `close()` | Close current position | Use in strategy |
| `getStatistics()` | Calculate performance metrics | No |

**Strategy Lifecycle:**
```
Initialize → Load Data → Loop Bars → onOpen() → onClose() → Next Bar → onStrategyEnd()
```

### 2. Backtester (`src/Backtester.php`)

Orchestrates backtesting of strategies against historical data.

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `run()` | `run(Assets $assets, ?string $rangeStart = null, ?string $rangeEnd = null): BaseStrategy` | Execute backtest on date range |
| `runOptimization()` | `runOptimization(Assets $assets, ?string $rangeStart = null, ?string $rangeEnd = null): array` | Run parameter optimization |

**Example Usage:**
```php
$backtester = new Backtester(
    strategy: TestStrategy::class,
    capital: 10000,
    logger: new Console(Level::Info)
);

$assets = new Assets('AAPL', Resolution::Daily);
$assets->addSource(new Csv(__DIR__ . '/data/AAPL.csv'));

$result = $backtester->run($assets, '2020-01-01', '2023-12-31');
$stats = $result->getStatistics();
```

### 3. Assets (`src/Assets.php`)

Manages OHLC market data for one or more tickers.

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `addSource()` | `addSource(LoaderInterface $source)` | Add data source |
| `addData()` | `addData(string $ticker, array $data)` | Add OHLC data |
| `getData()` | `getData(string $ticker): DataFrame` | Get ticker data |
| `getValue()` | `getValue(string $ticker, int $bar): float` | Get close price at bar |

**Data Structure:**

Each ticker's data is stored as a DataFrame with columns:
- `date` (Carbon instance)
- `open` (float)
- `high` (float)
- `low` (float)
- `close` (float)
- `volume` (int)

### 4. Investor (`src/Investor/Investor.php`)

Manages live trading execution with multiple strategies and data sources.

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `addInvestment()` | `addInvestment(Investment $investment)` | Add strategy to portfolio |
| `updateData()` | `updateData(?string $ticker = null)` | Fetch latest market data |
| `onOpen()` | `onOpen()` | Trigger OnOpen event for all strategies |
| `onClose()` | `onClose()` | Trigger OnClose event for all strategies |
| `saveState()` | `saveState(string $file)` | Persist state to JSON |
| `loadState()` | `loadState(string $file)` | Restore state from JSON |

### 5. Position (`src/Helpers/Position.php`)

Represents an open or closed trade position.

**Properties:**
```php
public Side $side;              // Long or Short
public QuantityType $qtyType;   // Percent or Units
public float $quantity;         // Amount
public float $entryPrice;       // Entry price
public ?float $exitPrice;       // Exit price (null if open)
public int $entryBar;           // Entry bar index
public ?int $exitBar;           // Exit bar index
public PositionStatus $status;  // Open or Closed
```

**Key Methods:**
- `getProfitLoss()`: Calculate P&L for position
- `getProfitLossPercent()`: Calculate P&L as percentage

## Creating Custom Strategies

### Step-by-Step Guide

**1. Create a new strategy class extending BaseStrategy:**

```php
<?php

namespace SimpleTrader;

use SimpleTrader\Helpers\Side;
use SimpleTrader\Helpers\QuantityType;

class MyCustomStrategy extends BaseStrategy
{
    // Define strategy parameters
    protected int $fastLength = 10;
    protected int $slowLength = 30;

    // Override onClose to implement strategy logic
    public function onClose(Event $event): void
    {
        // Get technical indicators
        $close = $this->assets->getData($this->ticker)['close'];
        $fastMA = trader_sma($close, $this->fastLength);
        $slowMA = trader_sma($close, $this->slowLength);

        // Get current values
        $currentFast = $fastMA[$this->currentBar];
        $currentSlow = $slowMA[$this->currentBar];
        $previousFast = $fastMA[$this->currentBar - 1];
        $previousSlow = $slowMA[$this->currentBar - 1];

        // Entry logic: Fast MA crosses above Slow MA
        if ($this->position === null &&
            $previousFast <= $previousSlow &&
            $currentFast > $currentSlow) {

            $this->entry(
                side: Side::Long,
                quantity: 100,
                quantityType: QuantityType::Percent,
                comment: "Golden Cross"
            );
        }

        // Exit logic: Fast MA crosses below Slow MA
        if ($this->position !== null &&
            $previousFast >= $previousSlow &&
            $currentFast < $currentSlow) {

            $this->close(comment: "Death Cross");
        }
    }
}
```

**2. Use the strategy in a backtest:**

```php
$backtester = new Backtester(
    strategy: MyCustomStrategy::class,
    capital: 10000,
    logger: new Console(Level::Info)
);

// Override parameters
$backtester->overrideParam('fastLength', 20);
$backtester->overrideParam('slowLength', 50);

$result = $backtester->run($assets);
```

### Strategy Best Practices

1. **Use onClose for most logic**: Strategy decisions are typically made after the bar closes
2. **Use onOpen for pre-market actions**: Useful for gap analysis or pre-market data
3. **Check position status before entry**: Always check if `$this->position === null` before entering
4. **Validate indicators**: Check for NaN values from trader functions
5. **Use comments**: Add descriptive comments to entry/close calls for debugging
6. **Avoid lookahead bias**: Only use data available at `$this->currentBar` or earlier

### Parameter Optimization

Define parameters to optimize using `OptimizationParam`:

```php
use SimpleTrader\Helpers\OptimizationParam;

$backtester->addOptimizationParam(
    new OptimizationParam('fastLength', 5, 50, 5)  // Start, End, Step
);

$backtester->addOptimizationParam(
    new OptimizationParam('slowLength', 20, 200, 20)
);

$results = $backtester->runOptimization($assets);
```

This will test all combinations of parameters and return results sorted by performance.

## Creating Custom Data Loaders

### Implementing LoaderInterface

Create a loader for custom data formats:

```php
<?php

namespace SimpleTrader\Loaders;

use Carbon\Carbon;
use SimpleTrader\Helpers\Ohlc;
use SimpleTrader\Helpers\Resolution;

class JsonLoader extends BaseLoader implements LoaderInterface
{
    public function __construct(
        private string $filePath,
        private string $ticker,
        private Resolution $resolution = Resolution::Daily
    ) {}

    public function load(): array
    {
        $json = file_get_contents($this->filePath);
        $data = json_decode($json, true);

        $result = [];
        foreach ($data['candles'] as $candle) {
            $result[] = new Ohlc(
                date: Carbon::parse($candle['timestamp']),
                open: $candle['open'],
                high: $candle['high'],
                low: $candle['low'],
                close: $candle['close'],
                volume: $candle['volume']
            );
        }

        return $result;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getResolution(): Resolution
    {
        return $this->resolution;
    }
}
```

**Usage:**
```php
$assets = new Assets('BTC', Resolution::Hourly);
$assets->addSource(new JsonLoader('./data/btc.json', 'BTC', Resolution::Hourly));
```

### Implementing SourceInterface (Live Data)

For real-time data sources:

```php
<?php

namespace SimpleTrader\Loaders;

use Carbon\Carbon;
use SimpleTrader\Helpers\Ohlc;
use SimpleTrader\Helpers\Resolution;

class AlphaVantageSource implements SourceInterface
{
    private string $apiKey;

    public function __construct(
        private string $ticker,
        private Resolution $resolution,
        string $apiKey
    ) {
        $this->apiKey = $apiKey;
    }

    public function getCurrentQuote(): Ohlc
    {
        $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol={$this->ticker}&apikey={$this->apiKey}";
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        $quote = $data['Global Quote'];

        return new Ohlc(
            date: Carbon::now(),
            open: floatval($quote['02. open']),
            high: floatval($quote['03. high']),
            low: floatval($quote['04. low']),
            close: floatval($quote['05. price']),
            volume: intval($quote['06. volume'])
        );
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function getResolution(): Resolution
    {
        return $this->resolution;
    }
}
```

## Creating Custom Notifiers

### Implementing NotifierInterface

```php
<?php

namespace SimpleTrader\Investor;

use SimpleTrader\BaseStrategy;

class SlackNotifier implements NotifierInterface
{
    private string $webhookUrl;
    private array $messages = [];

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function notify(BaseStrategy $strategy, string $message): void
    {
        $this->messages[] = [
            'strategy' => get_class($strategy),
            'ticker' => $strategy->getTicker(),
            'message' => $message
        ];
    }

    public function flush(): void
    {
        foreach ($this->messages as $msg) {
            $payload = json_encode([
                'text' => "[{$msg['strategy']}] {$msg['ticker']}: {$msg['message']}"
            ]);

            $ch = curl_init($this->webhookUrl);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }

        $this->messages = [];
    }
}
```

**Usage in Investor:**
```php
$investor = new Investor(new SlackNotifier($slackWebhookUrl));
```

## Testing Strategies

### Manual Testing with runner.php

1. **Create a test script:**

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use SimpleTrader\{Backtester, Assets};
use SimpleTrader\Loaders\Csv;
use SimpleTrader\Loggers\{Console, Level};
use SimpleTrader\Helpers\Resolution;
use SimpleTrader\Reporting\HtmlReport;

// Initialize backtester
$backtester = new Backtester(
    strategy: MyCustomStrategy::class,
    capital: 10000,
    logger: new Console(Level::Info)
);

// Load data
$assets = new Assets('AAPL', Resolution::Daily);
$assets->addSource(new Csv(__DIR__ . '/data/AAPL.csv'));

// Run backtest
$start = microtime(true);
$result = $backtester->run($assets, '2020-01-01', '2023-12-31');
$elapsed = microtime(true) - $start;

// Display results
$stats = $result->getStatistics();
echo "Net Profit: $" . number_format($stats['netProfit'], 2) . "\n";
echo "Win Rate: " . number_format($stats['winRate'], 2) . "%\n";
echo "Max Drawdown: " . number_format($stats['maxDrawdown'], 2) . "%\n";
echo "Sharpe Ratio: " . number_format($stats['sharpeRatio'], 2) . "\n";
echo "Execution time: " . number_format($elapsed, 2) . "s\n";

// Generate report
$report = new HtmlReport($result, $backtester);
file_put_contents(__DIR__ . '/report.html', $report->generate());
echo "Report saved to report.html\n";
```

2. **Run the test:**
```bash
php test_my_strategy.php
```

### Optimization Testing

```php
// Add optimization parameters
$backtester->addOptimizationParam(
    new OptimizationParam('fastLength', 5, 30, 5)
);
$backtester->addOptimizationParam(
    new OptimizationParam('slowLength', 20, 100, 10)
);

// Run optimization
$results = $backtester->runOptimization($assets);

// Generate comparison report
$report = new HtmlReport($results, $backtester);
file_put_contents(__DIR__ . '/optimization_report.html', $report->generate());
```

### Live Trading Testing

Use `MockNotifier` for testing without sending actual notifications:

```php
use SimpleTrader\Investor\{Investor, Investment, MockNotifier};

$investor = new Investor(new MockNotifier());

$investment = new Investment(
    strategy: MyCustomStrategy::class,
    source: new TradingViewSource('AAPL', Resolution::Daily),
    capital: 10000
);

$investor->addInvestment($investment);
$investor->updateData();
$investor->onClose();

// Check if positions were opened
foreach ($investor->getInvestments() as $inv) {
    $strategy = $inv->getStrategy();
    if ($strategy->getPosition() !== null) {
        echo "Position opened: " . $strategy->getPosition()->side->name . "\n";
    }
}
```

## Extending the Framework

### Adding Custom Events

Currently, the framework supports `OnOpen` and `OnClose` events. To add custom events:

**1. Extend the Event enum:**

```php
// src/Event.php
enum Event: string
{
    case OnOpen = 'onOpen';
    case OnClose = 'onClose';
    case OnTick = 'onTick';        // New event
    case OnHour = 'onHour';        // New event
}
```

**2. Add event handler to BaseStrategy:**

```php
// src/BaseStrategy.php
public function onTick(Event $event): void
{
    // Default implementation (can be overridden)
}

public function onHour(Event $event): void
{
    // Default implementation
}
```

**3. Trigger events in Backtester/Investor:**

```php
// In Backtester::run()
if ($this->isHourBoundary($bar)) {
    $this->strategy->onHour(Event::OnHour);
}
```

### Adding Custom Statistics

Extend `getStatistics()` in your strategy:

```php
class MyCustomStrategy extends BaseStrategy
{
    public function getStatistics(): array
    {
        $stats = parent::getStatistics();

        // Add custom metrics
        $stats['customMetric1'] = $this->calculateCustomMetric1();
        $stats['customMetric2'] = $this->calculateCustomMetric2();

        return $stats;
    }

    private function calculateCustomMetric1(): float
    {
        // Your calculation
    }
}
```

### Adding Report Customizations

Extend `HtmlReport` to customize report generation:

```php
class CustomHtmlReport extends HtmlReport
{
    public function generate(): string
    {
        $html = parent::generate();

        // Add custom sections
        $customSection = $this->generateCustomSection();
        $html = str_replace('</body>', $customSection . '</body>', $html);

        return $html;
    }

    private function generateCustomSection(): string
    {
        return '<div class="custom-section">Custom content</div>';
    }
}
```

## Code Style and Best Practices

### PHP Standards

1. **Use strict types:**
```php
<?php

declare(strict_types=1);
```

2. **Type everything:**
```php
// Good
public function calculate(float $value): float

// Bad
public function calculate($value)
```

3. **Use enums for fixed sets:**
```php
enum Side: string
{
    case Long = 'long';
    case Short = 'short';
}
```

4. **Use readonly for immutable properties (PHP 8.1+):**
```php
public function __construct(
    private readonly string $ticker,
    private readonly Resolution $resolution
) {}
```

### Strategy Development Guidelines

1. **Keep strategies focused**: One strategy = one idea
2. **Use descriptive parameter names**: `$fastLength` not `$l1`
3. **Document your logic**: Add comments explaining "why" not "what"
4. **Validate inputs**: Check for NaN, null, array bounds
5. **Use helper methods**: Break complex logic into smaller methods
6. **Log important events**: Use the logger for debugging

### Performance Optimization

1. **Cache indicator calculations:**
```php
private ?array $cachedSMA = null;

private function getSMA(): array
{
    if ($this->cachedSMA === null) {
        $this->cachedSMA = trader_sma($this->assets->getData($this->ticker)['close'], $this->length);
    }
    return $this->cachedSMA;
}
```

2. **Minimize DataFrame operations**: Pre-calculate what you can
3. **Use batch operations**: Prefer vectorized operations over loops
4. **Enable OPcache**: Already configured in docker/php.ini

### Error Handling

1. **Use custom exceptions:**
```php
use SimpleTrader\Exceptions\StrategyException;

if ($this->currentBar < $this->length) {
    throw new StrategyException("Insufficient data for indicator calculation");
}
```

2. **Handle edge cases:**
```php
// Check for NaN
if (is_nan($indicator[$this->currentBar])) {
    return; // Skip this bar
}

// Check array bounds
if ($this->currentBar < 1) {
    return; // Need previous bar
}
```

3. **Graceful degradation**: Don't crash on missing data

### Testing Checklist

Before deploying a strategy:

- [ ] Backtest on multiple date ranges
- [ ] Test with different capital amounts
- [ ] Verify indicators don't produce NaN
- [ ] Check edge cases (first/last bar)
- [ ] Optimize parameters to avoid overfitting
- [ ] Test with MockNotifier before live trading
- [ ] Review trade log for unexpected behavior
- [ ] Validate statistics are reasonable
- [ ] Check memory usage for long backtests
- [ ] Test state save/load for live trading

## Project Structure Reference

```
src/
├── Backtester.php              # Core backtesting engine
├── BaseStrategy.php            # Strategy base class
├── Assets.php                  # Market data manager
├── Event.php                   # Event enum
├── TestStrategy.php            # Example strategy
├── Exceptions/                 # Custom exceptions
│   ├── BacktesterException.php
│   ├── StrategyException.php
│   ├── InvestorException.php
│   ├── LoaderException.php
│   └── ShutdownException.php
├── Helpers/                    # Utilities
│   ├── Position.php            # Trade position
│   ├── Calculator.php          # Math utilities
│   ├── Ohlc.php               # OHLC data structure
│   ├── Side.php               # Long/Short enum
│   ├── Resolution.php         # Timeframe enum
│   ├── QuantityType.php       # Percent/Units enum
│   ├── PositionStatus.php     # Open/Closed enum
│   ├── OptimizationParam.php  # Parameter definition
│   └── ShutdownScheduler.php  # Shutdown tasks
├── Investor/                   # Live trading
│   ├── Investor.php           # Portfolio manager
│   ├── Investment.php         # Strategy container
│   ├── NotifierInterface.php  # Notification contract
│   ├── EmailNotifier.php      # Email implementation
│   └── MockNotifier.php       # Mock for testing
├── Loaders/                    # Data loading
│   ├── BaseLoader.php         # Base loader
│   ├── Csv.php                # CSV loader
│   ├── TradingViewSource.php  # Live data source
│   ├── LoaderInterface.php    # Loader contract
│   └── SourceInterface.php    # Source contract
├── Loggers/                    # Logging
│   ├── Console.php            # Console logger
│   ├── LoggerInterface.php    # Logger contract
│   └── Level.php              # Log level enum
└── Reporting/                  # Reports
    ├── HtmlReport.php         # HTML generator
    └── Graphs.php             # Chart generator
```

## Useful Resources

- **PECL Trader Functions**: https://www.php.net/manual/en/book.trader.php
- **MammothPHP DataFrame**: https://github.com/MammothPHP/WoollyM
- **JpGraph Documentation**: https://jpgraph.net/
- **Carbon DateTime**: https://carbon.nesbot.com/docs/

## Getting Help

If you encounter issues:

1. Check logs in `Console` output
2. Review generated HTML reports
3. Examine closed positions array
4. Add debug logging to your strategy
5. Test with smaller date ranges
6. Verify data quality with CSV files

## Contributing

When contributing to the framework:

1. Follow PSR-12 coding standards
2. Add type hints to all methods
3. Document public APIs with docblocks
4. Create examples for new features
5. Test on PHP 8.3+
6. Update this documentation

---

**Happy Trading!** 🚀
