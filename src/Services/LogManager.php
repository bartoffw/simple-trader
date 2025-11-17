<?php

namespace SimpleTrader\Services;

use SimpleTrader\Loggers\File;
use SimpleTrader\Loggers\Level;

/**
 * Centralized Log Manager Service
 *
 * Handles all log file operations including:
 * - Creating and managing log files
 * - Reading logs with pagination
 * - Configuring PHP error logging
 * - Providing metadata about available logs
 */
class LogManager
{
    private string $logsDir;
    private array $logFiles = [];

    /**
     * Log file definitions with metadata
     * Each log has: title, filename, icon, description, category
     */
    private static array $logDefinitions = [
        'Application Logs' => [
            'php-errors' => [
                'title' => 'PHP Errors',
                'filename' => 'php-errors.log',
                'icon' => 'exclamation-triangle',
                'description' => 'PHP errors, warnings, and notices'
            ],
            'app' => [
                'title' => 'Application',
                'filename' => 'app.log',
                'icon' => 'desktop',
                'description' => 'General application logs'
            ],
        ],
        'Command Logs' => [
            'update-quotes' => [
                'title' => 'Update Quotes',
                'filename' => 'update-quotes.log',
                'icon' => 'chart-line',
                'description' => 'Ticker quote update operations'
            ],
            'update-monitor' => [
                'title' => 'Update Monitors',
                'filename' => 'update-monitor.log',
                'icon' => 'sync',
                'description' => 'Strategy monitor daily updates'
            ],
            'monitor-backtest' => [
                'title' => 'Monitor Backtest',
                'filename' => 'monitor-backtest.log',
                'icon' => 'history',
                'description' => 'Initial backtest execution for monitors'
            ],
            'daily-update' => [
                'title' => 'Daily Update',
                'filename' => 'daily-update.log',
                'icon' => 'calendar-day',
                'description' => 'Master daily update dispatcher'
            ],
        ],
    ];

    /**
     * Constructor
     *
     * @param string $logsDir Base directory for all log files
     */
    public function __construct(string $logsDir)
    {
        $this->logsDir = rtrim($logsDir, '/');

        // Ensure logs directory exists
        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }
    }

    /**
     * Get the logs directory path
     *
     * @return string
     */
    public function getLogsDir(): string
    {
        return $this->logsDir;
    }

    /**
     * Get all log definitions with categories
     *
     * @return array
     */
    public function getLogDefinitions(): array
    {
        return self::$logDefinitions;
    }

    /**
     * Get a flat list of all log slugs
     *
     * @return array
     */
    public function getAllLogSlugs(): array
    {
        $slugs = [];
        foreach (self::$logDefinitions as $category => $logs) {
            foreach ($logs as $slug => $info) {
                $slugs[] = $slug;
            }
        }
        return $slugs;
    }

    /**
     * Get log information by slug
     *
     * @param string $slug Log identifier
     * @return array|null Log info or null if not found
     */
    public function getLogInfo(string $slug): ?array
    {
        foreach (self::$logDefinitions as $category => $logs) {
            if (isset($logs[$slug])) {
                $info = $logs[$slug];
                $info['slug'] = $slug;
                $info['category'] = $category;
                $info['path'] = $this->getLogPath($slug);
                $info['exists'] = file_exists($info['path']);
                $info['size'] = $info['exists'] ? filesize($info['path']) : 0;
                $info['modified'] = $info['exists'] ? filemtime($info['path']) : null;
                return $info;
            }
        }
        return null;
    }

    /**
     * Get the full path for a log file
     *
     * @param string $slug Log identifier
     * @return string Full path to log file
     */
    public function getLogPath(string $slug): string
    {
        foreach (self::$logDefinitions as $category => $logs) {
            if (isset($logs[$slug])) {
                return $this->logsDir . '/' . $logs[$slug]['filename'];
            }
        }
        throw new \InvalidArgumentException("Unknown log: {$slug}");
    }

    /**
     * Create a File logger instance for a specific log
     *
     * @param string $slug Log identifier
     * @param bool $alsoConsole Also output to console
     * @param Level $level Log level
     * @return File Logger instance
     */
    public function createLogger(string $slug, bool $alsoConsole = true, Level $level = Level::Info): File
    {
        $logPath = $this->getLogPath($slug);
        $logger = new File($logPath, $alsoConsole);
        $logger->setLevel($level);
        return $logger;
    }

    /**
     * Read lines from the end of a log file (tail)
     *
     * @param string $slug Log identifier
     * @param int $limit Number of lines to read
     * @param int $offset Number of lines to skip from the end
     * @return array ['lines' => array, 'total_lines' => int, 'has_more' => bool]
     */
    public function readLogTail(string $slug, int $limit = 1000, int $offset = 0): array
    {
        $logPath = $this->getLogPath($slug);

        if (!file_exists($logPath)) {
            return [
                'lines' => [],
                'total_lines' => 0,
                'has_more' => false,
                'offset' => $offset,
                'limit' => $limit
            ];
        }

        // Count total lines efficiently
        $totalLines = $this->countLines($logPath);

        if ($totalLines === 0) {
            return [
                'lines' => [],
                'total_lines' => 0,
                'has_more' => false,
                'offset' => $offset,
                'limit' => $limit
            ];
        }

        // Calculate which lines to read
        // offset=0 means read the last $limit lines
        // offset=1000 means skip the last 1000 lines and read the 1000 before that
        $endLine = $totalLines - $offset;
        $startLine = max(1, $endLine - $limit + 1);

        if ($endLine <= 0) {
            return [
                'lines' => [],
                'total_lines' => $totalLines,
                'has_more' => false,
                'offset' => $offset,
                'limit' => $limit
            ];
        }

        // Read the specific range of lines
        $lines = $this->readLineRange($logPath, $startLine, $endLine);

        $hasMore = $startLine > 1;

        return [
            'lines' => $lines,
            'total_lines' => $totalLines,
            'has_more' => $hasMore,
            'offset' => $offset,
            'limit' => $limit,
            'start_line' => $startLine,
            'end_line' => $endLine
        ];
    }

    /**
     * Count total lines in a file efficiently
     *
     * @param string $filePath Path to file
     * @return int Number of lines
     */
    private function countLines(string $filePath): int
    {
        $count = 0;
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return 0;
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 1048576); // Read 1MB at a time
            if ($chunk === false) {
                break;
            }
            $count += substr_count($chunk, "\n");
        }

        fclose($handle);
        return $count;
    }

    /**
     * Read a specific range of lines from a file
     *
     * @param string $filePath Path to file
     * @param int $startLine First line to read (1-indexed)
     * @param int $endLine Last line to read (1-indexed)
     * @return array Lines in the range
     */
    private function readLineRange(string $filePath, int $startLine, int $endLine): array
    {
        $lines = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return $lines;
        }

        $currentLine = 0;
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $currentLine++;

            if ($currentLine >= $startLine && $currentLine <= $endLine) {
                $lines[] = rtrim($line, "\r\n");
            }

            if ($currentLine > $endLine) {
                break;
            }
        }

        fclose($handle);
        return $lines;
    }

    /**
     * Clear a log file
     *
     * @param string $slug Log identifier
     * @return bool Success
     */
    public function clearLog(string $slug): bool
    {
        $logPath = $this->getLogPath($slug);

        if (!file_exists($logPath)) {
            return true;
        }

        return file_put_contents($logPath, '') !== false;
    }

    /**
     * Get statistics for all log files
     *
     * @return array Array of log stats
     */
    public function getLogStatistics(): array
    {
        $stats = [];

        foreach (self::$logDefinitions as $category => $logs) {
            foreach ($logs as $slug => $info) {
                $path = $this->logsDir . '/' . $info['filename'];
                $exists = file_exists($path);

                $stats[$slug] = [
                    'title' => $info['title'],
                    'category' => $category,
                    'exists' => $exists,
                    'size' => $exists ? filesize($path) : 0,
                    'size_human' => $exists ? $this->formatFileSize(filesize($path)) : '0 B',
                    'modified' => $exists ? date('Y-m-d H:i:s', filemtime($path)) : null,
                    'lines' => $exists ? $this->countLines($path) : 0
                ];
            }
        }

        return $stats;
    }

    /**
     * Format file size to human readable format
     *
     * @param int $bytes File size in bytes
     * @return string Formatted size
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Configure PHP error logging to use the unified logs directory
     *
     * @return void
     */
    public function configurePHPErrorLogging(): void
    {
        $errorLogPath = $this->logsDir . '/php-errors.log';

        ini_set('log_errors', '1');
        ini_set('error_log', $errorLogPath);
        ini_set('display_errors', '0');
    }

    /**
     * Write a message to the application log
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @return void
     */
    public function logApp(string $message, string $level = 'info'): void
    {
        $logPath = $this->logsDir . '/app.log';
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        $logLine = "[{$timestamp}][{$levelUpper}] {$message}\n";

        file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    }
}
