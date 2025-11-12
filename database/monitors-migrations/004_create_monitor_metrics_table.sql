-- Create monitor metrics table
-- Stores performance metrics separately for backtest and forward test periods

CREATE TABLE IF NOT EXISTS monitor_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    metric_type VARCHAR(20) NOT NULL,  -- 'backtest' or 'forward'
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_return DECIMAL(10, 4),  -- Total return percentage
    annualized_return DECIMAL(10, 4),  -- Annualized return percentage
    sharpe_ratio DECIMAL(10, 4),
    max_drawdown DECIMAL(10, 4),
    win_rate DECIMAL(10, 4),  -- Percentage of winning trades
    total_trades INTEGER DEFAULT 0,
    winning_trades INTEGER DEFAULT 0,
    losing_trades INTEGER DEFAULT 0,
    avg_win DECIMAL(15, 2),
    avg_loss DECIMAL(15, 2),
    profit_factor DECIMAL(10, 4),
    final_equity DECIMAL(15, 2),
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(monitor_id, metric_type)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_metrics_monitor ON monitor_metrics(monitor_id);
CREATE INDEX IF NOT EXISTS idx_metrics_type ON monitor_metrics(metric_type);
