<?php

namespace App\Actions\Security;

use App\Enums\SecurityOutboxStatus;
use App\Jobs\DeliverSecurityOutboxMessage;
use App\Models\SecurityOutboxMessage;
use Throwable;

final class DispatchPendingSecurityOutbox
{
    public function __invoke(): int
    {
        $staleBefore = now()->subMinutes((int) config('security.outbox_stale_minutes', 15));

        SecurityOutboxMessage::query()
            ->where('status', SecurityOutboxStatus::Processing)
            ->where('processing_started_at', '<=', $staleBefore)
            ->update([
                'status' => SecurityOutboxStatus::Pending,
                'processing_started_at' => null,
                'available_at' => now(),
            ]);

        $dispatched = 0;

        foreach (SecurityOutboxMessage::query()
            ->where('status', SecurityOutboxStatus::Pending)
            ->where('available_at', '<=', now())
            ->select('id')
            ->lazyById(100) as $message) {
            $claimed = SecurityOutboxMessage::query()
                ->whereKey($message->id)
                ->where('status', SecurityOutboxStatus::Pending)
                ->update([
                    'status' => SecurityOutboxStatus::Processing,
                    'processing_started_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            try {
                DeliverSecurityOutboxMessage::dispatch($message->id);
                $dispatched++;
            } catch (Throwable $exception) {
                SecurityOutboxMessage::query()->whereKey($message->id)->update([
                    'status' => SecurityOutboxStatus::Pending,
                    'processing_started_at' => null,
                ]);

                throw $exception;
            }
        }

        return $dispatched;
    }
}
