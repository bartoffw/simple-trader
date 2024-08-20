<?php

namespace SimpleTrader\Investor;

use PHPMailer\PHPMailer\PHPMailer;
use SimpleTrader\Loggers\Level;

class EmailNotifier implements NotifierInterface
{
    protected PHPMailer $mail;
    protected array $summary = [];
    protected array $notifications = [];
    protected bool $hasErrors = false;


    public function __construct(string $smtpHost, string $smtpPort, string $smtpUsername, string $smtpPassword,
                                string $fromEmail, protected string $toEmail)
    {
        $this->mail = new PHPMailer(true);
        $this->mail->isSMTP();
        $this->mail->isHTML();
        $this->mail->Host = $smtpHost;
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $smtpUsername;
        $this->mail->Password = $smtpPassword;
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port = $smtpPort;
        $this->mail->setFrom($fromEmail, 'Simple Trader Mailer');
    }

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
        $summary = implode('', $this->summary);
        $allNotifications = '<h3>All Events</h3>' .
            '<pre>' . implode("\n", $this->notifications) . '</pre>';
        $this->mail->Subject = ($this->hasErrors ? '[ERROR] ' : '[INFO] ') . "Simple Trader Notifier";
        $this->mail->Body = $summary . $allNotifications;
        $this->mail->addAddress($this->toEmail);
        return $this->mail->send();
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getNotifications(): array
    {
        return $this->notifications;
    }

    protected static function getDateTime()
    {
        return date('Y-m-d H:i:s');
    }
}