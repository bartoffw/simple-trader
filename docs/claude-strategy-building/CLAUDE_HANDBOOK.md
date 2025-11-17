# Claude Autonomous Strategy Building Handbook

## Overview

This handbook guides you through the autonomous process of creating, testing, and optimizing trading strategies using the Simple-Trader framework. You have access to CLI commands that enable you to manage tickers, create strategies, run backtests, and analyze results.

## Quick Reference: Available Commands

```bash
# Ticker Management
php commands/list-tickers.php --format=json
php commands/add-ticker.php --symbol=AAPL --exchange=NASDAQ --source=TradingViewSource
php commands/update-quotes.php --ticker-id=<id>

# Strategy Management
php commands/list-strategies.php --format=json
php commands/create-strategy.php --name=MyStrategy --params='{"length":20}'

# Backtesting
php commands/run-backtest.php --strategy=<name> --tickers=<ids> --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD --format=json --no-save

# Results Analysis
php commands/get-backtest-results.php --id=<id> --format=json --summary-only
php commands/get-backtest-results.php --strategy=<name> --last=5 --format=json --compare
```

## Autonomous Workflow: Step-by-Step Process

### Phase 1: Initial Setup and Data Assessment

**Step 1: Check Available Tickers**
```bash
php commands/list-tickers.php --format=json
```

Critical checks:
- Verify tickers have current pricing data (`data_current: true`)
- Confirm volume data is available (`has_volume: true`)
- Note the date range (`first_date`, `last_date`)
- Record ticker IDs for backtesting

**Step 2: Add Tickers (if needed)**
```bash
php commands/add-ticker.php --symbol=SPY --exchange=NYSE --source=TradingViewSource --fetch-quotes --format=json
```

**Step 3: Update Quote Data**
```bash
php commands/update-quotes.php --ticker-id=<id>
# Or update all:
php commands/update-quotes.php
```

### Phase 2: Strategy Development

**Step 4: Understand User's Requirements**

Parse the user's prompt for:
- Market type (trending, mean-reverting, volatile)
- Asset class preferences (stocks, ETFs, crypto)
- Risk tolerance (conservative, moderate, aggressive)
- Time horizon (short-term, swing, long-term)
- Specific indicators or patterns mentioned

**Step 5: List Existing Strategies**
```bash
php commands/list-strategies.php --format=json --details
```

Use this to:
- Find similar strategies to adapt
- Understand common patterns
- Avoid duplicating existing work

**Step 6: Create a New Strategy**

Option A: Use CLI to create a template
```bash
php commands/create-strategy.php --name=MyTrendStrategy \
  --display-name="Trend Following Strategy" \
  --description="Enters long positions during uptrends using EMA crossovers" \
  --params='{"fast_ema":12,"slow_ema":26,"atr_multiplier":2.0}' \
  --template=advanced --format=json
```

Option B: Directly create/edit the PHP file using your file tools
```php
// src/MyTrendStrategy.php
namespace SimpleTrader;

class MyTrendStrategy extends BaseStrategy
{
    protected string $strategyName = 'Trend Following';
    protected array $strategyParameters = [
        'fast_ema' => 12,
        'slow_ema' => 26,
        'atr_multiplier' => 2.0
    ];

    // Implement onOpen, onClose, onStrategyEnd...
}
```

### Phase 3: Backtesting and Analysis

**Step 7: Design Test Plan**

Split available data into:
- **In-Sample (Training)**: 70% of historical data
- **Out-of-Sample (Validation)**: 30% of historical data

Example for 2020-2024 data:
- In-Sample: 2020-01-01 to 2022-10-31
- Out-of-Sample: 2022-11-01 to 2024-12-31

**Step 8: Run Initial Backtest**
```bash
php commands/run-backtest.php \
  --strategy=MyTrendStrategy \
  --tickers=1,2,3 \
  --start-date=2020-01-01 \
  --end-date=2022-10-31 \
  --initial-capital=10000 \
  --format=json \
  --no-save
```

Parse the JSON output for key metrics.

**Step 9: Analyze Results**

Critical metrics to evaluate:

```json
{
  "return_percent": Target > 15% annually,
  "win_rate": Target > 50%,
  "profit_factor": Target > 1.5 (excellent > 2.0),
  "max_drawdown_percent": Target < 20%,
  "sharpe_ratio": Target > 1.0 (excellent > 2.0),
  "total_trades": Ensure sufficient sample size (> 30 trades)
}
```

**Decision Matrix:**
- If `profit_factor < 1.0`: Strategy is losing money, needs major changes
- If `win_rate < 40%`: Entry logic needs improvement
- If `max_drawdown > 25%`: Risk management needs adjustment
- If `sharpe_ratio < 0.5`: Poor risk-adjusted returns, reconsider approach
- If `total_trades < 20`: Insufficient data, extend test period or adjust signals

### Phase 4: Iterative Optimization

**Step 10: Parameter Optimization**

Test parameter variations systematically:

```bash
# Test different EMA periods
php commands/run-backtest.php --strategy=MyTrendStrategy --tickers=1 \
  --start-date=2020-01-01 --end-date=2022-10-31 --format=json --no-save \
  --param:fast_ema=8 --param:slow_ema=21

php commands/run-backtest.php --strategy=MyTrendStrategy --tickers=1 \
  --start-date=2020-01-01 --end-date=2022-10-31 --format=json --no-save \
  --param:fast_ema=10 --param:slow_ema=30

php commands/run-backtest.php --strategy=MyTrendStrategy --tickers=1 \
  --start-date=2020-01-01 --end-date=2022-10-31 --format=json --no-save \
  --param:fast_ema=15 --param:slow_ema=40
```

Or use built-in optimization:
```bash
php commands/run-backtest.php --strategy=MyTrendStrategy --tickers=1 \
  --start-date=2020-01-01 --end-date=2022-10-31 --format=json \
  --optimize --opt:fast_ema=5:20:5 --opt:slow_ema=20:50:10
```

**Step 11: Logic Refinement**

Common improvements:
1. **Add filters**: Volume confirmation, trend strength
2. **Improve entries**: Multiple condition confluence
3. **Better exits**: Trailing stops, profit targets
4. **Risk management**: Position sizing, stop losses

Edit strategy directly:
```php
// Example: Add volume filter to entry
protected function checkEntryConditions(Assets $assets, Carbon $dateTime): void
{
    foreach ($this->indicators as $ticker => $data) {
        $avgVolume = array_sum(array_slice($data['ohlcv']['volume'], -20)) / 20;
        $currentVolume = end($data['ohlcv']['volume']);

        // Only enter if volume is above average
        if ($currentVolume < $avgVolume * 1.2) {
            continue;
        }

        // Rest of entry logic...
    }
}
```

### Phase 5: Validation and Finalization

**Step 12: Out-of-Sample Validation**

Test best parameters on unseen data:
```bash
php commands/run-backtest.php --strategy=MyTrendStrategy --tickers=1 \
  --start-date=2022-11-01 --end-date=2024-12-31 --format=json
```

Compare in-sample vs out-of-sample:
- Similar performance = robust strategy
- Much worse = likely overfitted
- Better performance = possibly lucky

**Step 13: Determine Completion**

Stop optimization when:
1. **Diminishing returns**: Improvements < 5% per iteration
2. **Overfitting risk**: Parameters too specific (e.g., fast_ema=13.7)
3. **Consistent results**: In-sample and out-of-sample metrics align
4. **Target achieved**: Meets user's requirements

**Red flags to stop:**
- Adding complexity without meaningful improvement
- Very high in-sample performance but poor out-of-sample
- Parameter values at extreme boundaries

## Strategy Building Patterns

### Pattern 1: Trend Following
```php
// EMA/SMA crossover, breakout strategies
// Good for: trending markets
// Key metrics: Profit factor, average win size
protected function checkEntryConditions($assets, $dateTime) {
    // Fast EMA crosses above slow EMA
    // Price above moving average
    // Volume confirmation
}
```

### Pattern 2: Mean Reversion
```php
// Bollinger Bands, RSI oversold/overbought
// Good for: range-bound markets
// Key metrics: Win rate, frequency
protected function checkEntryConditions($assets, $dateTime) {
    // RSI below 30 (oversold)
    // Price at lower Bollinger Band
    // Volume spike
}
```

### Pattern 3: Momentum
```php
// Relative strength, rate of change
// Good for: strong directional moves
// Key metrics: Maximum trade value, Sharpe ratio
protected function checkEntryConditions($assets, $dateTime) {
    // High ROC (rate of change)
    // Strong volume
    // New highs/lows
}
```

## Important Technical Indicators

PHP Trader extension functions (available in strategies):

```php
// Moving Averages
$sma = trader_sma($closes, $period);
$ema = trader_ema($closes, $period);
$wma = trader_wma($closes, $period);

// Oscillators
$rsi = trader_rsi($closes, $period);
$stoch = trader_stoch($highs, $lows, $closes, $k_period, $k_slow, $d_period);
$macd = trader_macd($closes, $fast, $slow, $signal);

// Volatility
$atr = trader_atr($highs, $lows, $closes, $period);
$bbands = trader_bbands($closes, $period, $upper_dev, $lower_dev);

// Volume
$obv = trader_obv($closes, $volumes);
$adl = trader_ad($highs, $lows, $closes, $volumes);

// Pattern Recognition
$patterns = trader_cdlengulfing($opens, $highs, $lows, $closes);
```

## Error Handling and Troubleshooting

### Common Issues

1. **"Not enough history"**
   - Increase lookback period in data
   - Check ticker has sufficient quotes
   - Adjust `getMaxLookbackPeriod()`

2. **"Strategy not found"**
   - Ensure file is in src/ directory
   - File must be named *Strategy.php
   - Class must extend BaseStrategy

3. **No trades generated**
   - Entry conditions too restrictive
   - Check indicator calculations
   - Verify data range includes opportunities

4. **Poor performance**
   - Overfitting to specific conditions
   - Not enough trades for statistical significance
   - Wrong market regime for strategy type

## Reporting to User

### Initial Report
```
## Strategy Development Started

Based on your requirements for [user's goal], I'm creating a [strategy type] strategy.

### Data Assessment
- Tickers available: [list]
- Date range: [start] to [end]
- Volume data: [yes/no]

### Initial Strategy Design
- Type: [trend following / mean reversion / momentum]
- Key indicators: [list]
- Risk management: [stop loss %, position sizing]

### Test Plan
- In-sample period: [dates] (training)
- Out-of-sample period: [dates] (validation)

Beginning backtest...
```

### Iteration Report
```
## Iteration #N Results

### Performance Summary
- Return: [X]%
- Win Rate: [X]%
- Profit Factor: [X]
- Max Drawdown: [X]%
- Sharpe Ratio: [X]

### Analysis
[What worked / what didn't]

### Next Steps
[Specific changes to make]
```

### Final Report
```
## Strategy Optimization Complete

### Final Strategy: [Name]
- File: src/[Name].php
- Parameters: [final params]

### Performance Summary
| Metric | In-Sample | Out-of-Sample |
|--------|-----------|---------------|
| Return % | X% | X% |
| Win Rate | X% | X% |
| Profit Factor | X | X |
| Max Drawdown | X% | X% |
| Sharpe Ratio | X | X |

### Why Optimization Stopped
[Reason: convergence / target met / overfitting risk]

### Strategy Characteristics
- Best for: [market conditions]
- Risk level: [conservative/moderate/aggressive]
- Recommended capital: $[amount]

### Recommendations
1. [Monitor these conditions]
2. [Reoptimize when...]
3. [Risk considerations]
```

## Best Practices

1. **Always use JSON format** for parsing results
2. **Save successful backtests** (remove `--no-save`) for comparison
3. **Start simple** and add complexity gradually
4. **Document reasoning** in strategy comments
5. **Test on multiple tickers** to ensure generalization
6. **Keep parameter values reasonable** (avoid extreme optimization)
7. **Monitor trade count** - need enough for statistical significance
8. **Check consistency** between in-sample and out-of-sample
9. **Consider transaction costs** in real-world performance
10. **Be honest with user** about limitations and risks
