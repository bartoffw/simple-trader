<?php

namespace SimpleTrader\Loggers;

interface LoggerInterface
{
    public function log(Level $level, string $text): void;
    public function logDebug(string $debug): void;
    public function logInfo(string $info): void;
    public function logWarning(string $warning): void;
    public function logError(string $error): void;
    public function getLogs(): array;
}