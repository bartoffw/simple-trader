<?php

namespace SimpleTrader\Services;

use SimpleTrader\Database\BacktestRepository;
use SimpleTrader\Loggers\LoggerInterface;
use SimpleTrader\Loggers\Level;

/**
 * Backtest Logger
 *
 * Custom logger that writes logs to database for real-time viewing
 */
class BacktestLogger implements LoggerInterface
{
    private BacktestRepository $backtestRepository;
    private int $runId;
    private Level $level = Level::Info;
    private string $buffer = '';
    private int $flushThreshold = 10; // Flush after 10 log lines
    private int $lineCount = 0;

    public function __construct(BacktestRepository $backtestRepository, int $runId)
    {
        $this->backtestRepository = $backtestRepository;
        $this->runId = $runId;
    }

    public function setLevel(Level $level): void
    {
        $this->level = $level;
    }

    public function log(Level $level, string $text): void
    {
        $this->writeLog($level->name, $text);
    }

    public function logDebug(string $debug): void
    {
        if ($this->level->value >= Level::Debug->value) {
            $this->writeLog('DEBUG', $debug);
        }
    }

    public function logInfo(string $info): void
    {
        if ($this->level->value >= Level::Info->value) {
            $this->writeLog('INFO', $info);
        }
    }

    public function logWarning(string $warning): void
    {
        if ($this->level->value >= Level::Warning->value) {
            $this->writeLog('WARNING', $warning);
        }
    }

    public function logError(string $error): void
    {
        if ($this->level->value >= Level::Error->value) {
            $this->writeLog('ERROR', $error);
        }
    }

    public function getLogs(): array
    {
        // Return logs as array of lines
        // Note: For database logger, we don't keep logs in memory
        // The logs are stored in database and retrieved via BacktestRepository
        return [];
    }

    private function writeLog(string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [{$level}] {$message}\n";

        // Add to buffer
        $this->buffer .= $logLine;
        $this->lineCount++;

        // Also output to console for background process visibility
        echo $logLine;

        // Flush buffer periodically to avoid too many database writes
        if ($this->lineCount >= $this->flushThreshold) {
            $this->flush();
        }
    }

    /**
     * Flush buffer to database
     */
    public function flush(): void
    {
        if (!empty($this->buffer)) {
            $this->backtestRepository->appendLog($this->runId, $this->buffer);
            $this->buffer = '';
            $this->lineCount = 0;
        }
    }

    /**
     * Ensure buffer is flushed when logger is destroyed
     */
    public function __destruct()
    {
        $this->flush();
    }
}
