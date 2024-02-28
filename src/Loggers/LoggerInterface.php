<?php

namespace SimpleTrader\Loggers;

interface LoggerInterface
{
    public static function logDebug(string $debug);
    public static function logInfo(string $info);
    public static function logWarning(string $warning);
    public static function logError(string $error);
}