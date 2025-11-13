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

        // Create log file for this run in a writable location
        $logDir = $this->projectRoot . '/database/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/backtest-' . $runId . '.log';

        // Execute command in background with logging
        if (stripos(PHP_OS, 'WIN') === 0) {
            // Windows
            pclose(popen("start /B " . $command . " > " . escapeshellarg($logFile) . " 2>&1", "r"));
        } else {
            // Unix/Linux/Mac - capture output for debugging
            exec($command . " >> " . escapeshellarg($logFile) . " 2>&1 &");
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
        return escapeshellcmd($this->phpBinary) . ' ' . escapeshellarg($scriptPath) . ' --run-id=' . escapeshellarg($runId);
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

    /**
     * Health check for stalled backtests
     * Detects backtests that are stuck in pending/running status and restarts or fails them
     *
     * @param \SimpleTrader\Database\BacktestRepository $repository
     * @return array Statistics about actions taken
     */
    public function healthCheck(\SimpleTrader\Database\BacktestRepository $repository): array
    {
        $stats = [
            'checked' => 0,
            'restarted' => 0,
            'failed' => 0
        ];

        // Get all pending and running backtests
        $pendingBacktests = $repository->getAllBacktests('pending');
        $runningBacktests = $repository->getAllBacktests('running');

        $now = new \DateTime();

        // Check pending backtests (stuck for more than 30 seconds)
        foreach ($pendingBacktests as $backtest) {
            $stats['checked']++;
            $createdAt = new \DateTime($backtest['created_at']);
            $secondsSinceCreated = $now->getTimestamp() - $createdAt->getTimestamp();
            $minutesSinceCreated = $secondsSinceCreated / 60;

            // For very new backtests (< 1 minute), wait at least 30 seconds before first restart
            // For older backtests, restart immediately as they clearly failed
            $shouldRestart = false;
            if ($secondsSinceCreated >= 30 && $secondsSinceCreated < 60) {
                // First restart attempt after 30 seconds
                $shouldRestart = true;
            } elseif ($minutesSinceCreated >= 1) {
                // Subsequent restart attempts every health check if still pending after 1 minute
                $shouldRestart = true;
            }

            if ($shouldRestart) {
                // Backtest has been pending for too long, restart it
                $timestamp = date('Y-m-d H:i:s');
                if ($secondsSinceCreated < 60) {
                    $logMessage = "\n[{$timestamp}] [HEALTH CHECK] Backtest hasn't started after {$secondsSinceCreated} seconds. Attempting to start...\n";
                } else {
                    $logMessage = "\n[{$timestamp}] [HEALTH CHECK] Backtest was stuck in pending status for " . round($minutesSinceCreated, 1) . " minutes. Attempting to restart...\n";
                }
                $repository->appendLog($backtest['id'], $logMessage);

                $this->startRun($backtest['id']);
                $stats['restarted']++;
            }
        }

        // Check running backtests (stuck for more than 30 minutes without update)
        foreach ($runningBacktests as $backtest) {
            $stats['checked']++;
            $startedAt = $backtest['started_at'] ? new \DateTime($backtest['started_at']) : new \DateTime($backtest['created_at']);
            $minutesSinceStarted = ($now->getTimestamp() - $startedAt->getTimestamp()) / 60;

            // If running for more than 30 minutes, likely stalled
            if ($minutesSinceStarted > 30) {
                // Mark as failed with log message
                $timestamp = date('Y-m-d H:i:s');
                $errorMsg = "Backtest timed out after " . round($minutesSinceStarted) . " minutes";
                $logMessage = "\n[{$timestamp}] [ERROR] {$errorMsg}\n";

                $repository->appendLog($backtest['id'], $logMessage);
                $repository->updateError($backtest['id'], $errorMsg);
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
