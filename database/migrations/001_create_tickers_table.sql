-- Migration: Create tickers table
-- Created: 2025-11-05
-- Description: Initial schema for ticker management

-- Tickers table
CREATE TABLE IF NOT EXISTS tickers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol VARCHAR(10) NOT NULL UNIQUE,
    exchange VARCHAR(10) NOT NULL,
    csv_path VARCHAR(255) NOT NULL,
    enabled BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Index for faster lookups
CREATE INDEX IF NOT EXISTS idx_tickers_enabled ON tickers(enabled);
CREATE INDEX IF NOT EXISTS idx_tickers_symbol ON tickers(symbol);

-- Audit log table (for tracking changes)
CREATE TABLE IF NOT EXISTS ticker_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticker_id INTEGER NOT NULL,
    action VARCHAR(20) NOT NULL, -- 'created', 'updated', 'deleted', 'enabled', 'disabled'
    old_values TEXT,
    new_values TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticker_id) REFERENCES tickers(id) ON DELETE CASCADE
);

-- Index for audit log queries
CREATE INDEX IF NOT EXISTS idx_audit_ticker_id ON ticker_audit_log(ticker_id);
CREATE INDEX IF NOT EXISTS idx_audit_timestamp ON ticker_audit_log(timestamp);
