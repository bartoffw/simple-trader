# Claude Strategy Building Documentation

This directory contains comprehensive documentation for building trading strategies autonomously with Claude.

## Documents

### For Claude (AI Assistant)
- **[CLAUDE_HANDBOOK.md](CLAUDE_HANDBOOK.md)** - Complete reference guide for Claude to follow when building strategies. Includes command references, workflow steps, optimization patterns, and best practices.

### For Users
- **[USER_GUIDE.md](USER_GUIDE.md)** - User-friendly guide explaining how to request strategy development from Claude, interpret results, and guide the process.

## Quick Start

1. **Point Claude to the handbook:**
   ```
   Please read docs/claude-strategy-building/CLAUDE_HANDBOOK.md to understand how to build strategies autonomously.
   ```

2. **Describe your strategy requirements:**
   ```
   I want to build a trend-following strategy for SPY. Use EMA crossovers with 15% max drawdown.
   ```

3. **Let Claude work:**
   - Claude will check data availability
   - Create/adapt a strategy
   - Run backtests iteratively
   - Report results and recommendations

## New CLI Commands

These commands were added to support autonomous strategy building:

| Command | Purpose | Key Flags |
|---------|---------|-----------|
| `list-tickers.php` | View all tickers with data status | `--format=json`, `--with-stats` |
| `add-ticker.php` | Add new tickers to track | `--symbol`, `--exchange`, `--source` |
| `list-strategies.php` | View available strategies | `--format=json`, `--details` |
| `create-strategy.php` | Generate strategy templates | `--name`, `--params`, `--template` |
| `get-backtest-results.php` | Retrieve and compare results | `--id`, `--strategy`, `--compare` |

All commands support `--format=json` for easy parsing by Claude.

## Workflow Overview

```
User Request
    ↓
Data Assessment (list-tickers.php)
    ↓
Strategy Creation (create-strategy.php + file editing)
    ↓
Initial Backtest (run-backtest.php)
    ↓
Results Analysis (get-backtest-results.php)
    ↓
Parameter Optimization (iterate)
    ↓
Out-of-Sample Validation
    ↓
Final Report to User
```

## Key Features

- **JSON output** for all commands enables automated parsing
- **In-sample/out-of-sample testing** prevents overfitting
- **Iterative optimization** with convergence detection
- **Multiple strategy templates** (basic and advanced)
- **Comprehensive metrics** for decision making
- **Comparison tools** for evaluating multiple runs

## Important Considerations

1. **Data Quality**: Ensure tickers have current, complete data
2. **Statistical Significance**: Need enough trades (30+) for reliable results
3. **Overfitting Risk**: Watch for strategies that only work on training data
4. **Realistic Expectations**: Professional traders target 15-25% annual returns
5. **Risk Management**: Always include stop losses and position sizing

## Support

For issues or questions:
- Check the main [USER_GUIDE.md](../../USER_GUIDE.md)
- Review [DEVELOPER_GUIDE.md](../../DEVELOPER_GUIDE.md)
- See command help: `php commands/<command>.php --help`
