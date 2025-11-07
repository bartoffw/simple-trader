-- Migration: Create quotes table for OHLCV data
-- Created: 2025-11-07
-- Description: Store daily quotation data for all tickers

-- Enable foreign key constraints
PRAGMA foreign_keys = ON;

-- Quotes table - stores OHLCV (Open, High, Low, Close, Volume) data
CREATE TABLE IF NOT EXISTS quotes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticker_id INTEGER NOT NULL,
    date DATE NOT NULL,
    open DECIMAL(20, 8) NOT NULL,
    high DECIMAL(20, 8) NOT NULL,
    low DECIMAL(20, 8) NOT NULL,
    close DECIMAL(20, 8) NOT NULL,
    volume BIGINT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticker_id) REFERENCES tickers(id) ON DELETE CASCADE,
    UNIQUE(ticker_id, date)
);

-- Compound index for fast ticker-specific queries sorted by date
CREATE INDEX IF NOT EXISTS idx_quotes_ticker_date ON quotes(ticker_id, date DESC);

-- Index for date-based queries across all tickers
CREATE INDEX IF NOT EXISTS idx_quotes_date ON quotes(date);

-- Index for ticker-specific latest date queries
CREATE INDEX IF NOT EXISTS idx_quotes_ticker_latest ON quotes(ticker_id, date DESC);
