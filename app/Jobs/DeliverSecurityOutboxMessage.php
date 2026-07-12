<?php

namespace App\Jobs;

use App\Enums\SecurityOutboxStatus;
use App\Enums\SecurityOutboxType;
use App\Mail\ChangeEmailVerificationMail;
use App\Mail\EmailChangedNotificationMail;
use App\Models\SecurityOutboxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class DeliverSecurityOutboxMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout;

    public function __construct(public readonly int $messageId)
    {
        $this->timeout = (int) config('security.delivery_timeout_seconds', 30);
        $this->onQueue('security');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('security.delivery_backoff_seconds', [30, 120, 300, 900]));
    }

    public function handle(): void
    {
        $lock = Cache::lock("security-outbox:{$this->messageId}", 60);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            $message = SecurityOutboxMessage::find($this->messageId);

            if (! $message || $message->status === SecurityOutboxStatus::Sent) {
                return;
            }

            $message->forceFill([
                'status' => SecurityOutboxStatus::Processing,
                'processing_started_at' => now(),
                'attempts' => $message->attempts + 1,
                'last_error' => null,
            ])->save();

            try {
                Mail::to($message->recipient)->send($this->mailable($message));
            } catch (Throwable $exception) {
                $message->forceFill([
                    'status' => SecurityOutboxStatus::Pending,
                    'processing_started_at' => null,
                    'available_at' => now()->addSeconds($this->retryDelay($message->attempts)),
                    'last_error' => Str::limit($exception::class.': '.$exception->getMessage(), 2000, ''),
                ])->save();

                throw $exception;
            }

            $message->forceFill([
                'status' => SecurityOutboxStatus::Sent,
                'processing_started_at' => null,
                'sent_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }
    }

    private function mailable(SecurityOutboxMessage $message): ChangeEmailVerificationMail|EmailChangedNotificationMail
    {
        return match ($message->type) {
            SecurityOutboxType::ChangeEmailVerification => new ChangeEmailVerificationMail($message->payload['verification_url']),
            SecurityOutboxType::EmailChangedNotification => new EmailChangedNotificationMail($message->payload['new_email']),
        };
    }

    private function retryDelay(int $attempt): int
    {
        return [30, 120, 300, 900, 1800][min(max($attempt - 1, 0), 4)];
    }
}
