-- Create monitor daily snapshots table
-- Stores state of the monitor for each day

CREATE TABLE IF NOT EXISTS monitor_daily_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    date DATE NOT NULL,
    equity DECIMAL(15, 2) NOT NULL,  -- Total equity (cash + positions value)
    cash DECIMAL(15, 2) NOT NULL,  -- Available cash
    positions_value DECIMAL(15, 2) NOT NULL DEFAULT 0.00,  -- Total value of open positions
    positions TEXT,  -- JSON encoded current positions
    strategy_state TEXT,  -- JSON encoded strategy internal state
    daily_return DECIMAL(10, 4),  -- Daily return percentage
    cumulative_return DECIMAL(10, 4),  -- Cumulative return from start
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(monitor_id, date)
);

-- Indexes for faster queries
CREATE INDEX IF NOT EXISTS idx_snapshots_monitor ON monitor_daily_snapshots(monitor_id);
CREATE INDEX IF NOT EXISTS idx_snapshots_date ON monitor_daily_snapshots(date);
CREATE INDEX IF NOT EXISTS idx_snapshots_monitor_date ON monitor_daily_snapshots(monitor_id, date DESC);
