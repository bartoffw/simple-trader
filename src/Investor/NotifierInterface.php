<?php

namespace SimpleTrader\Investor;

use SimpleTrader\Loggers\Level;

interface NotifierInterface
{
    public function addSummary(string $text): void;
    public function addLogs(array $logs): void;
    public function notify(Level $level, string $text): bool;
    public function notifyDebug(string $debug): bool;
    public function notifyInfo(string $info): bool;
    public function notifyWarning(string $warning): bool;
    public function notifyError(string $error): bool;
    public function sendAllNotifications(): bool;
}