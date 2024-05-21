<?php

namespace SimpleTrader\Executor;

use SimpleTrader\Loggers\Level;

class EmailNotifier implements NotifierInterface
{
    public function __construct(string $smtpHost, string $smtpPort, string $smtpUsername, string $smtpPassword)
    {

    }

    public function notify(Level $level, string $text): bool
    {
        return false;
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
        return $this->notify(Level::Error, $error);
    }
}