<?php

namespace SimpleTrader\Services;

/**
 * Background Runner Service
 *
 * Launches backtest runs as background processes
 */
class BackgroundRunner
{
    private string $projectRoot;
    private string $phpBinary;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->phpBinary = PHP_BINARY;
    }

    /**
     * Start a backtest run in the background
     *
     * @param int $runId Run ID to execute
     * @return bool True if process started successfully
     */
    public function startRun(int $runId): bool
    {
        $command = $this->buildCommand($runId);

        // Execute command in background
        if (strto(PHP_OS, 'WIN') === 0) {
            // Windows
            pclose(popen("start /B " . $command, "r"));
        } else {
            // Unix/Linux/Mac
            exec($command . " > /dev/null 2>&1 &");
        }

        return true;
    }

    /**
     * Build the command to execute
     *
     * @param int $runId
     * @return string
     */
    private function buildCommand(int $runId): string
    {
        $scriptPath = $this->projectRoot . '/commands/run-backtest.php';
        return escapeshellcmd($this->phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($runId);
    }

    /**
     * Check if a run process is still running
     * (Simplified - in production you'd check actual process status)
     *
     * @param int $runId
     * @return bool
     */
    public function isRunning(int $runId): bool
    {
        // For now, we rely on the database status
        // In a production system, you'd check if the process is actually running
        return false;
    }
}
