-- Migration: Add source field to tickers table
-- Created: 2025-11-07
-- Description: Add data source selection for each ticker

-- Enable foreign key constraints
PRAGMA foreign_keys = ON;

-- Add source column to tickers table (skip if already exists)
-- Note: SQLite doesn't support IF NOT EXISTS for ALTER TABLE ADD COLUMN
-- If this fails with "duplicate column" error, it means the column already exists - safe to ignore

-- Check if column exists by querying pragma
-- ALTER TABLE tickers ADD COLUMN source VARCHAR(100) DEFAULT 'TradingViewSource';
-- Commented out as column already exists in your database

-- Create index for source lookups (this is idempotent with IF NOT EXISTS)
CREATE INDEX IF NOT EXISTS idx_tickers_source ON tickers(source);
