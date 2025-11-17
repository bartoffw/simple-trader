<?php

namespace SimpleTrader\Loggers;

class File implements LoggerInterface
{
    protected Level $level = Level::Debug;
    protected array $logs = [];
    protected string $logFile;
    protected bool $alsoConsole;
    protected $fileHandle = null;

    /**
     * Constructor
     *
     * @param string $logFile Path to log file
     * @param bool $alsoConsole Also output to console
     */
    public function __construct(string $logFile, bool $alsoConsole = true)
    {
        $this->logFile = $logFile;
        $this->alsoConsole = $alsoConsole;

        // Ensure the directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Open file for appending
        $this->fileHandle = fopen($logFile, 'a');
        if ($this->fileHandle === false) {
            throw new \RuntimeException("Failed to open log file: {$logFile}");
        }
    }

    public function __destruct()
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
        }
    }

    protected function getDateTime()
    {
        return date('Y-m-d H:i:s');
    }

    public function setLevel(Level $level): void
    {
        $this->level = $level;
    }

    public function log(Level $level, string $text): void
    {
        $showLog = true;
        if ($this->level === Level::Info && $level === Level::Debug) {
            $showLog = false;
        }
        if ($this->level === Level::Warning && ($level === Level::Debug || $level === Level::Info)) {
            $showLog = false;
        }
        if ($this->level === Level::Error && $level !== Level::Error) {
            $showLog = false;
        }
        if ($showLog) {
            $this->logs[] = $text;
            $logLine = '[' . self::getDateTime() . '][' . $level->value . '] ' . $text . "\n";

            // Write to file
            if ($this->fileHandle) {
                fwrite($this->fileHandle, $logLine);
                fflush($this->fileHandle);
            }

            // Also write to console if enabled
            if ($this->alsoConsole) {
                echo $logLine;
            }
        }
    }

    public function logDebug(string $debug): void
    {
        $this->log(Level::Debug, $debug);
    }

    public function logInfo(string $info): void
    {
        $this->log(Level::Info, $info);
    }

    public function logWarning(string $warning): void
    {
        $this->log(Level::Warning, $warning);
    }

    public function logError(string $error): void
    {
        $this->log(Level::Error, $error);
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Get the log file path
     *
     * @return string
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Write a raw line to the log file (without formatting)
     *
     * @param string $line
     */
    public function writeRaw(string $line): void
    {
        if ($this->fileHandle) {
            fwrite($this->fileHandle, $line . "\n");
            fflush($this->fileHandle);
        }
        if ($this->alsoConsole) {
            echo $line . "\n";
        }
    }
}
