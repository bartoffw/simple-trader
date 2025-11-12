-- Migration: Create backtests table for backtesting runs
-- This table stores all backtest runs with their configurations and results

PRAGMA foreign_keys = OFF;  -- No foreign keys since tickers are in a different database

-- Create backtests table
CREATE TABLE IF NOT EXISTS backtests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    strategy_class VARCHAR(100) NOT NULL,
    strategy_parameters TEXT,  -- JSON
    tickers TEXT NOT NULL,  -- JSON array of ticker IDs
    benchmark_ticker_id INTEGER,  -- Store ID only, no FK constraint
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    initial_capital DECIMAL(20, 2) NOT NULL DEFAULT 10000.00,
    is_optimization BOOLEAN DEFAULT 0,
    optimization_params TEXT,  -- JSON, nullable
    status VARCHAR(20) DEFAULT 'pending',  -- pending, running, completed, failed
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME,
    completed_at DATETIME,
    execution_time_seconds DECIMAL(10, 2),
    report_html TEXT,  -- Full HTML report
    log_output TEXT,  -- Execution logs
    result_metrics TEXT,  -- JSON with net profit, transactions, etc.
    error_message TEXT
);

-- Create index on status for filtering
CREATE INDEX IF NOT EXISTS idx_backtests_status ON backtests(status);

-- Create index on created_at for sorting
CREATE INDEX IF NOT EXISTS idx_backtests_created_at ON backtests(created_at DESC);

-- Create index on strategy for filtering
CREATE INDEX IF NOT EXISTS idx_backtests_strategy ON backtests(strategy_class);
