<?php

namespace SimpleTrader\Investor;

use PHPMailer\PHPMailer\PHPMailer;
use SimpleTrader\Loggers\Level;

class EmailNotifier implements NotifierInterface
{
    protected PHPMailer $mail;
    protected array $notifications = [];


    public function __construct(string $smtpHost, string $smtpPort, string $smtpUsername, string $smtpPassword,
                                string $fromEmail, protected string $toEmail)
    {
        $this->mail = new PHPMailer(true);
        $this->mail->isSMTP();
        $this->mail->Host = $smtpHost;
        $this->mail->SMTPAuth = true;
        $this->mail->Username = $smtpUsername;
        $this->mail->Password = $smtpPassword;
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port = $smtpPort;
        $this->mail->setFrom($fromEmail, 'Simple Trader Mailer');
    }

    public function notify(Level $level, string $text): bool
    {
        $this->notifications[$level->value][] = $text;
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
        return $this->notify(Level::Error, $error);
    }

    public function sendAllNotifications(?Level $onlyLevel = null): bool
    {
        $success = true;
        foreach ($this->notifications as $level => $notifications) {
            if ($onlyLevel !== null && $onlyLevel->value !== $level) {
                continue;
            }
            $allNotifications = implode('<br/>', $notifications);
            $this->mail->Subject = "[$level] Simple Trader Notifier";
            $this->mail->Body = $allNotifications;
            $this->mail->addAddress($this->toEmail);
            $success = $success && $this->mail->send();
        }
        return $success;
    }
}