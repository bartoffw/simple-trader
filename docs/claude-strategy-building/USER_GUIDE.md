# User Guide: Building Trading Strategies with Claude

## Introduction

This guide explains how to use Claude to autonomously develop, test, and optimize trading strategies using the Simple-Trader framework. Claude can manage tickers, create strategies, run backtests, and iteratively improve performance based on your requirements.

## Getting Started

### Prerequisites

1. **Simple-Trader installed** with all dependencies
2. **Tickers configured** with historical pricing data
3. **Claude Code** or Claude with tool access
4. **Handbook loaded** - Point Claude to `docs/claude-strategy-building/CLAUDE_HANDBOOK.md`

### Initial Setup

Before starting, ensure your environment has:
- PHP 8.1+ with Trader extension
- Configured tickers in the database
- At least 1-2 years of historical data for meaningful testing

## How to Request Strategy Development

### Basic Request Format

```
"Create a trading strategy for [asset] that [strategy description] with [risk tolerance]"
```

### Example Requests

**Trend Following:**
```
"Build a trend-following strategy for SPY that enters when the market shows strong upward momentum. Use EMAs for trend identification. I have moderate risk tolerance and want to hold positions for days to weeks."
```

**Mean Reversion:**
```
"Create a mean-reversion strategy for tech stocks (AAPL, MSFT, GOOGL) that buys oversold conditions. Use RSI and Bollinger Bands. Conservative risk approach with stop losses."
```

**Momentum:**
```
"Develop a momentum strategy that rotates between the strongest performing ETFs. Use relative strength and volume confirmation. Aggressive risk tolerance."
```

### Key Information to Provide

1. **Asset preferences**: Specific tickers or types (stocks, ETFs, crypto)
2. **Strategy type**: Trend following, mean reversion, breakout, etc.
3. **Risk tolerance**: Conservative, moderate, aggressive
4. **Time horizon**: Scalping, swing trading, position trading
5. **Specific requirements**: Maximum drawdown, minimum win rate, etc.

## What Claude Will Do

### Autonomous Workflow

1. **Data Assessment**
   - Check available tickers and their data quality
   - Verify sufficient historical data exists
   - Ensure volume data is available (if needed)

2. **Strategy Creation**
   - Design strategy based on your requirements
   - Implement using appropriate technical indicators
   - Create entry/exit rules and risk management

3. **Initial Testing**
   - Run backtests on in-sample data (training period)
   - Analyze key performance metrics
   - Identify areas for improvement

4. **Iterative Optimization**
   - Adjust parameters systematically
   - Refine entry/exit logic
   - Improve risk management rules

5. **Validation**
   - Test on out-of-sample data (unseen period)
   - Compare in-sample vs out-of-sample performance
   - Ensure strategy is robust and not overfitted

6. **Final Report**
   - Provide complete performance summary
   - Explain strategy logic and parameters
   - Offer recommendations for live use

## Understanding Claude's Reports

### Performance Metrics

| Metric | Good | Excellent | What It Means |
|--------|------|-----------|---------------|
| **Return %** | >15% annually | >25% annually | Total profit relative to starting capital |
| **Win Rate** | >50% | >60% | Percentage of profitable trades |
| **Profit Factor** | >1.5 | >2.0 | Gross profit / Gross loss (higher = better) |
| **Max Drawdown** | <20% | <10% | Largest peak-to-trough decline |
| **Sharpe Ratio** | >1.0 | >2.0 | Risk-adjusted return (higher = better) |

### Sample Report Interpretation

```
Return: +45.2%
Win Rate: 58%
Profit Factor: 2.1
Max Drawdown: -15.3%
Sharpe Ratio: 1.8
```

**Analysis**: This is a strong strategy with:
- High returns (45%)
- Better than coin flip win rate (58%)
- Excellent profit factor (2.1 means $2.10 profit for every $1 loss)
- Acceptable drawdown (15% is manageable)
- Good risk-adjusted returns (Sharpe 1.8)

## Guiding Claude's Process

### If You Want More Conservative Results

```
"The drawdown is too high for my comfort. Please add tighter stop losses and reduce position sizes to target a maximum drawdown of 10%."
```

### If You Want More Aggressive Results

```
"I'm comfortable with higher risk. Remove the stop loss and let winning trades run longer. Target 30%+ annual returns."
```

### If Strategy Isn't Trading Enough

```
"The strategy only made 10 trades in 2 years. Please loosen the entry conditions so it trades more frequently."
```

### If Strategy Is Overtrading

```
"Too many trades are generating excessive commissions. Add filters to reduce trade frequency while maintaining quality signals."
```

## CLI Commands Reference

### Ticker Management

**List all tickers:**
```bash
php commands/list-tickers.php
```
Shows all configured tickers with data status.

**Add a new ticker:**
```bash
php commands/add-ticker.php --symbol=TSLA --exchange=NASDAQ --source=TradingViewSource
```

**Update pricing data:**
```bash
php commands/update-quotes.php --ticker-id=5
```

### Strategy Management

**List available strategies:**
```bash
php commands/list-strategies.php --details
```

**Create new strategy:**
```bash
php commands/create-strategy.php --name=MyStrategy --params='{"length":20}'
```

### Backtesting

**Run a backtest:**
```bash
php commands/run-backtest.php --strategy=MyStrategy --tickers=1,2 \
  --start-date=2020-01-01 --end-date=2023-12-31
```

**View results:**
```bash
php commands/get-backtest-results.php --id=15
```

**Compare multiple tests:**
```bash
php commands/get-backtest-results.php --strategy=MyStrategy --last=5 --compare
```

## Best Practices

### 1. Provide Sufficient Data

Ensure your tickers have:
- At least 2 years of historical data
- Current data (updated within last week)
- Volume data for volume-based strategies

### 2. Set Realistic Expectations

- Professional traders target 15-25% annual returns
- Higher returns usually mean higher risk
- Past performance doesn't guarantee future results

### 3. Understand Risk Tolerance

- **Conservative**: Max drawdown <10%, steady returns
- **Moderate**: Max drawdown <20%, balanced risk/reward
- **Aggressive**: Max drawdown <30%, high returns accepted

### 4. Request Validation

Always ask Claude to:
- Test on out-of-sample data
- Compare in-sample vs out-of-sample results
- Report if strategy appears overfitted

### 5. Review the Code

Claude will create PHP files in `src/`. Review the logic:
- Entry conditions make logical sense
- Exit rules are reasonable
- Risk management is appropriate

## Warning Signs

### Overfitting Indicators

- Very specific parameter values (e.g., EMA period of 13.7)
- Much better in-sample than out-of-sample performance
- Strategy only works on specific date ranges
- Too many parameters/conditions

### Red Flags

- Win rate >80% (might be looking at cherry-picked trades)
- Profit factor >5.0 (likely too few trades or data-snooping)
- Annual returns >100% with low drawdown (unrealistic)

## Example Session

**User:**
```
I want to build a simple moving average crossover strategy for SPY. I have 5 years of data (2019-2024). Target 20% annual returns with max 15% drawdown.
```

**Claude's Response:**
```
I'll develop an SMA crossover strategy for SPY. Let me first check the data:

[Checks tickers]
SPY data available: 2019-01-02 to 2024-11-15 (1,472 trading days)
Volume data: Yes

Test plan:
- In-sample: 2019-01-01 to 2022-06-30 (training)
- Out-of-sample: 2022-07-01 to 2024-11-15 (validation)

Creating strategy with initial parameters:
- Fast SMA: 20 periods
- Slow SMA: 50 periods

[Runs backtests]
[Reports results]
[Makes adjustments]

Final Results:
- Return: 18.5% annually
- Max Drawdown: 14.2%
- Win Rate: 55%
- Sharpe Ratio: 1.4

Strategy is saved in src/SMACrossoverStrategy.php
```

## Advanced Topics

### Multiple Ticker Strategies

Request strategies that trade multiple assets:
```
"Create a rotation strategy that switches between SPY, QQQ, and TLT based on which has the strongest momentum. Rebalance monthly."
```

### Complex Entry Conditions

Request confluence-based entries:
```
"Entry should require: price above 200 SMA, RSI between 40-60, and volume above 20-day average. All conditions must be met."
```

### Custom Risk Management

Specify detailed exit rules:
```
"Use ATR-based trailing stops. Initial stop at 2x ATR below entry. Trail the stop up as price increases but never down."
```

## Troubleshooting

### "No tickers found"
```bash
php commands/list-tickers.php
# If empty, add tickers:
php commands/add-ticker.php --symbol=SPY --exchange=NYSE --source=TradingViewSource
```

### "Strategy file not created"
Check that the class name is valid:
- Must start with uppercase letter
- Must end with "Strategy"
- Only letters and numbers allowed

### "Backtest returns no results"
- Verify date range has data
- Check strategy file has no PHP syntax errors
- Ensure getMaxLookbackPeriod() returns correct value

## Final Notes

Claude will iterate and optimize until:
1. Performance targets are met, OR
2. Further optimization shows diminishing returns, OR
3. Risk of overfitting becomes too high

Always treat backtested results as optimistic estimates. Real trading involves slippage, commissions, and market impact not fully captured in backtests.

For production use, consider:
- Paper trading the strategy first
- Starting with smaller position sizes
- Monitoring performance vs backtest expectations
- Regularly reassessing strategy validity
