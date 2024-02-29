<?php

namespace SimpleTrader\Loggers;

interface LoggerInterface
{
    public function log(Level $level, string $text);
    public function logDebug(string $debug);
    public function logInfo(string $info);
    public function logWarning(string $warning);
    public function logError(string $error);
}