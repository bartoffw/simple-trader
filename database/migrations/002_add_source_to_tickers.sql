-- Migration: Add source field to tickers table
-- Created: 2025-11-07
-- Description: Add data source selection for each ticker

-- Enable foreign key constraints
PRAGMA foreign_keys = ON;

-- Add source column to tickers table
ALTER TABLE tickers ADD COLUMN source VARCHAR(100) DEFAULT 'TradingViewSource';

-- Create index for source lookups
CREATE INDEX IF NOT EXISTS idx_tickers_source ON tickers(source);
