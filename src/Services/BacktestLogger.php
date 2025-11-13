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

    public function logDebug(string $message, array $context = []): void
    {
        if ($this->level->value >= Level::Debug->value) {
            $this->writeLog('DEBUG', $message, $context);
        }
    }

    public function logInfo(string $message, array $context = []): void
    {
        if ($this->level->value >= Level::Info->value) {
            $this->writeLog('INFO', $message, $context);
        }
    }

    public function logWarning(string $message, array $context = []): void
    {
        if ($this->level->value >= Level::Warning->value) {
            $this->writeLog('WARNING', $message, $context);
        }
    }

    public function logError(string $message, array $context = []): void
    {
        if ($this->level->value >= Level::Error->value) {
            $this->writeLog('ERROR', $message, $context);
        }
    }

    private function writeLog(string $level, string $message, array $context = []): void
    {
        // Replace context placeholders
        foreach ($context as $key => $value) {
            $message = str_replace('{' . $key . '}', $value, $message);
        }

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
            $this->runRepository->appendLog($this->runId, $this->buffer);
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
