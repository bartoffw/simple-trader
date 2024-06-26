<?php

namespace SimpleTrader\Loggers;


class Console implements LoggerInterface
{
    protected Level $level = Level::Debug;
    protected array $logs = [];


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
            echo '[' . self::getDateTime() . '][' . $level->value . '] ' . $text . "\n";
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
}