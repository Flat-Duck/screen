<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ChangeEmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $verificationUrl) {}

    public function build(): self
    {
        return $this->subject('Confirm your new email address')
            ->markdown('mail.change-email');
    }
}
