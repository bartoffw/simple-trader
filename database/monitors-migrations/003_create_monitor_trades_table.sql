-- Create monitor trades table
-- Stores all trades executed by monitors

CREATE TABLE IF NOT EXISTS monitor_trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    date DATE NOT NULL,
    ticker_id INTEGER NOT NULL,  -- Reference to ticker (stored as ID, no FK due to different DB)
    ticker_symbol VARCHAR(50) NOT NULL,  -- Denormalized for display
    action VARCHAR(10) NOT NULL,  -- 'buy' or 'sell'
    quantity DECIMAL(15, 4) NOT NULL,
    price DECIMAL(15, 4) NOT NULL,
    total_value DECIMAL(15, 2) NOT NULL,  -- quantity * price
    commission DECIMAL(15, 2) DEFAULT 0.00,
    notes TEXT,  -- Additional info (e.g., stop loss, reason)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_trades_monitor ON monitor_trades(monitor_id);
CREATE INDEX IF NOT EXISTS idx_trades_date ON monitor_trades(date DESC);
CREATE INDEX IF NOT EXISTS idx_trades_monitor_date ON monitor_trades(monitor_id, date DESC);
CREATE INDEX IF NOT EXISTS idx_trades_ticker ON monitor_trades(ticker_id);
