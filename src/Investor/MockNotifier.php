<?php

namespace SimpleTrader\Investor;

use PHPMailer\PHPMailer\PHPMailer;
use SimpleTrader\Loggers\Level;

class MockNotifier implements NotifierInterface
{
    protected array $summary = [];
    protected array $notifications = [];
    protected bool $hasErrors = false;


    public function addSummary(string $text): void
    {
        $this->summary[] = $text;
    }

    public function addLogs(array $logs): void
    {
        if (!empty($logs)) {
            $this->notifications += $logs;
        }
    }

    public function notify(Level $level, string $text): bool
    {
        $this->notifications[] = '[' . self::getDateTime() . '][' . $level->value . '] ' . $text;
        return true;
    }

    public function notifyDebug(string $debug): bool
    {
        return $this->notify(Level::Debug, $debug);
    }

    public function notifyInfo(string $info): bool
    {
        return $this->notify(Level::Info, $info);
    }

    public function notifyWarning(string $warning): bool
    {
        return $this->notify(Level::Warning, $warning);
    }

    public function notifyError(string $error): bool
    {
        $this->hasErrors = true;
        return $this->notify(Level::Error, $error);
    }

    public function sendAllNotifications(): bool
    {
        return true;
    }

    protected static function getDateTime()
    {
        return date('Y-m-d H:i:s');
    }
}