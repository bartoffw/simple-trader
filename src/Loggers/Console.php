<?php

namespace SimpleTrader\Loggers;


class Console implements LoggerInterface
{
    protected function getDateTime()
    {
        return date('Y-m-d H:i:s');
    }

    public function log(Level $level, string $text)
    {
        echo '[' . self::getDateTime() . '][' . $level->value . '] ' . $text . "\n";
    }

    public function logDebug(string $debug)
    {
        $this->log(Level::Debug, $debug);
    }

    public function logInfo(string $info)
    {
        $this->log(Level::Info, $info);
    }

    public function logWarning(string $warning)
    {
        $this->log(Level::Warning, $warning);
    }

    public function logError(string $error)
    {
        $this->log(Level::Error, $error);
    }
}