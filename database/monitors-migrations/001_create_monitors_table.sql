-- Create monitors table
-- Stores configuration for each strategy monitor

CREATE TABLE IF NOT EXISTS monitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    strategy_class VARCHAR(100) NOT NULL,
    tickers TEXT NOT NULL,  -- Comma-separated ticker IDs
    strategy_parameters TEXT,  -- JSON encoded parameters
    start_date DATE NOT NULL,  -- Start date for monitoring
    initial_capital DECIMAL(15, 2) NOT NULL DEFAULT 10000.00,
    status VARCHAR(20) NOT NULL DEFAULT 'initializing',  -- 'initializing', 'active', 'stopped'
    backtest_completed_at DATETIME,  -- When initial backtest finished
    last_processed_date DATE,  -- Last date that was processed
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Index for faster lookups
CREATE INDEX IF NOT EXISTS idx_monitors_status ON monitors(status);
CREATE INDEX IF NOT EXISTS idx_monitors_strategy ON monitors(strategy_class);
