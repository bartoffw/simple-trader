-- Migration: Drop runs table from tickers database
-- Runs have been moved to a separate database (runs.db)

-- Drop indexes first
DROP INDEX IF EXISTS idx_runs_status;
DROP INDEX IF EXISTS idx_runs_created_at;
DROP INDEX IF EXISTS idx_runs_strategy;

-- Drop the runs table
DROP TABLE IF EXISTS runs;
