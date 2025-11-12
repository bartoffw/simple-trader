-- Add progress tracking fields to monitors table

ALTER TABLE monitors ADD COLUMN backtest_progress INTEGER DEFAULT 0;  -- Progress percentage (0-100)
ALTER TABLE monitors ADD COLUMN backtest_status VARCHAR(50);  -- 'pending', 'running', 'completed', 'failed'
ALTER TABLE monitors ADD COLUMN backtest_error TEXT;  -- Error message if backtest failed
ALTER TABLE monitors ADD COLUMN backtest_current_date DATE;  -- Current date being processed
