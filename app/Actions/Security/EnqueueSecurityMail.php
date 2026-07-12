<?php

namespace App\Actions\Security;

use App\Enums\SecurityOutboxStatus;
use App\Enums\SecurityOutboxType;
use App\Jobs\DeliverSecurityOutboxMessage;
use App\Models\SecurityOutboxMessage;

final class EnqueueSecurityMail
{
    /** @param array<string, string> $payload */
    public function __invoke(SecurityOutboxType $type, string $recipient, array $payload): SecurityOutboxMessage
    {
        $message = SecurityOutboxMessage::create([
            'type' => $type,
            'recipient' => $recipient,
            'payload' => $payload,
            'status' => SecurityOutboxStatus::Pending,
            'available_at' => now(),
        ]);

        DeliverSecurityOutboxMessage::dispatch($message->id)->afterCommit();

        return $message;
    }
}
