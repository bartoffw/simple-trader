<?php

namespace SimpleTrader\Loggers;

class Console implements LoggerInterface
{
    protected static function getDateTime()
    {
        return date('Y-m-d H:i:s');
    }

    protected static function outputString(string $level, string $text)
    {
        echo '[' . self::getDateTime() . '][' . $level . '] ' . $text . "\n";
    }

    public static function logDebug(string $debug)
    {
        self::outputString('DEBUG', $debug);
    }

    public static function logInfo(string $info)
    {
        self::outputString('INFO', $info);
    }

    public static function logWarning(string $warning)
    {
        self::outputString('WARNING', $warning);
    }

    public static function logError(string $error)
    {
        self::outputString('ERROR', $error);
    }
}