-- Migration: Drop runs table from tickers database
-- Runs have been moved to a separate database (runs.db)
-- This migration is safe to run even if the runs table never existed

-- Drop indexes first (if they exist)
DROP INDEX IF EXISTS idx_runs_status;
DROP INDEX IF EXISTS idx_runs_created_at;
DROP INDEX IF EXISTS idx_runs_strategy;

-- Drop the runs table (if it exists)
DROP TABLE IF EXISTS runs;
