<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The step-up proof for an account with neither a password nor 2FA (see StepUpService) —
 * a stolen bearer token alone can no longer delete the account, change its email, or
 * touch 2FA; the caller must also be able to read mail sent to the account's own
 * (already-verified) inbox.
 */
class AccountConfirmationCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function build(): self
    {
        return $this->subject('Your confirmation code')
            ->markdown('mail.account-confirmation-code');
    }
}
