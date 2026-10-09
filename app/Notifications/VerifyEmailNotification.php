<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

final class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ?string $verificationCode = null)
    {
        $this->afterCommit();
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = parent::toMail($notifiable);

        if ($this->verificationCode !== null) {
            $message->line(__('Or enter this 6-digit code in the Akukas app:'))
                ->line($this->verificationCode);
        }

        return $message;
    }
}
