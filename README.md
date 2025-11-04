# Simple-Trader

**Test your trading strategies easily in PHP**

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-purple.svg)](https://www.php.net/)

Simple-Trader is a powerful PHP library for backtesting and executing trading strategies. Whether you're a quantitative trader, algorithmic trading enthusiast, or financial developer, this framework provides everything you need to test and deploy your trading ideas.

## Features

- **Backtest** strategies against historical market data
- **Optimize** strategy parameters automatically
- **Live Trading** with real-time data integration
- **Performance Reports** with interactive charts and metrics
- **Email Notifications** for trade events
- **Technical Indicators** via PECL Trader extension
- **Multi-Asset** support for portfolio strategies
- **State Persistence** for live trading sessions

## Quick Start

### Installation

Using Docker (recommended):

```bash
git clone https://github.com/bartoffw/simple-trader.git
cd simple-trader
docker-compose up -d
docker-compose exec trader composer install
```

### Run Your First Backtest

```bash
docker-compose exec trader php runner.php
```

This will backtest the example strategy and generate an HTML report.

## Documentation

- **[User Guide](USER_GUIDE.md)** - Complete guide for using Simple-Trader
  - Installation instructions
  - Creating strategies
  - Backtesting and optimization
  - Live trading setup
  - Email notifications
  - Troubleshooting

- **[Developer Guide](DEVELOPER_GUIDE.md)** - Technical documentation for extending the framework
  - Architecture overview
  - Core components
  - Creating custom loaders
  - Creating custom notifiers
  - Extending the framework
  - Best practices

## Example Strategy

```php
<?php

namespace SimpleTrader;

use SimpleTrader\Helpers\{Side, QuantityType};

class MyStrategy extends BaseStrategy
{
    protected int $fastMA = 10;
    protected int $slowMA = 30;

    public function onClose(Event $event): void
    {
        $close = $this->assets->getData($this->ticker)['close'];
        $fast = trader_sma($close, $this->fastMA);
        $slow = trader_sma($close, $this->slowMA);

        // Golden Cross - Buy Signal
        if ($this->position === null &&
            $fast[$this->currentBar - 1] <= $slow[$this->currentBar - 1] &&
            $fast[$this->currentBar] > $slow[$this->currentBar]) {

            $this->entry(
                side: Side::Long,
                quantity: 100,
                quantityType: QuantityType::Percent
            );
        }

        // Death Cross - Sell Signal
        if ($this->position !== null &&
            $fast[$this->currentBar - 1] >= $slow[$this->currentBar - 1] &&
            $fast[$this->currentBar] < $slow[$this->currentBar]) {

            $this->close(comment: "Exit signal");
        }
    }
}
```

## Key Metrics

Simple-Trader calculates comprehensive performance metrics:

- Net Profit / Loss
- Return %
- Win Rate
- Profit Factor
- Sharpe Ratio
- Maximum Drawdown
- Average Trade
- Total Trades

## Requirements

- PHP 8.3 or higher
- PHP Extensions: bcmath, gd, sqlite3, trader
- Composer

## Technologies

- **PHP 8.3+** - Modern PHP with enums and typed properties
- **MammothPHP/WoollyM** - DataFrame library for data manipulation
- **PECL Trader** - Technical analysis indicators (SMA, RSI, MACD, etc.)
- **JpGraph** - Chart generation
- **PHPMailer** - Email notifications
- **Carbon** - Date/time handling
- **SQLite** - Data persistence

## Entry Points

The project includes several ready-to-use scripts:

- `runner.php` - Basic backtesting
- `runner-optimization.php` - Parameter optimization
- `investor.php` - Live trading execution
- `converter.php` - CSV to SQLite data import

## Performance Reports

Backtests generate comprehensive HTML reports with:

- Capital curve visualization
- Drawdown analysis
- Trade log with all entries/exits
- Statistical summary
- Parameter comparison (for optimization)

## Live Trading

Simple-Trader supports live trading with:

- Real-time data from TradingView
- State persistence between runs
- Email notifications on trades
- Multiple strategy management
- Automatic data updates

Example cron job (runs daily at market close):
```bash
0 16 * * 1-5 cd /path/to/simple-trader && docker-compose exec -T trader php investor.php
```

## Configuration

Copy `.env.example` to `.env` and configure:

```env
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
FROM_EMAIL=your-email@gmail.com
TO_EMAIL=recipient@example.com
```

## Project Structure

```
simple-trader/
├── src/
│   ├── Backtester.php              # Core backtesting engine
│   ├── BaseStrategy.php            # Strategy base class
│   ├── Assets.php                  # Market data manager
│   ├── Investor/                   # Live trading
│   ├── Loaders/                    # Data loading
│   ├── Reporting/                  # HTML reports
│   ├── Helpers/                    # Utilities
│   ├── Loggers/                    # Logging
│   └── Exceptions/                 # Custom exceptions
├── runner.php                      # Backtest script
├── runner-optimization.php         # Optimization script
├── investor.php                    # Live trading script
├── USER_GUIDE.md                   # User documentation
└── DEVELOPER_GUIDE.md              # Developer documentation
```

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please:

1. Follow PSR-12 coding standards
2. Add type hints to all methods
3. Document public APIs
4. Create examples for new features
5. Update documentation

## Disclaimer

**This software is for educational and research purposes only. Trading involves substantial risk of loss. Past performance does not guarantee future results. The authors are not responsible for any financial losses incurred through the use of this software.**

Always test thoroughly with paper trading before risking real capital.

## Support

- [User Guide](USER_GUIDE.md) - Complete usage instructions
- [Developer Guide](DEVELOPER_GUIDE.md) - Technical documentation
- [Issues](https://github.com/bartoffw/simple-trader/issues) - Bug reports and feature requests

---

**Happy Trading!** 📈