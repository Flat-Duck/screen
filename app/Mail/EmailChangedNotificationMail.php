<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the OLD address once an email change is confirmed — the account's rightful
 * owner (if it wasn't them who made the change) has no other way to notice, since every
 * other artifact of this flow (the confirmation link, the account itself) only ever
 * touches the NEW address from here on.
 */
class EmailChangedNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $newEmail) {}

    public function build(): self
    {
        return $this->subject('Your account email address was changed')
            ->markdown('mail.email-changed-notification');
    }
}
