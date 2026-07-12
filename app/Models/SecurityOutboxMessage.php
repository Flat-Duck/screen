<?php

namespace App\Models;

use App\Enums\SecurityOutboxStatus;
use App\Enums\SecurityOutboxType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property SecurityOutboxType $type
 * @property string $recipient
 * @property array<string, string> $payload
 * @property SecurityOutboxStatus $status
 * @property int $attempts
 */
class SecurityOutboxMessage extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SecurityOutboxType::class,
            'status' => SecurityOutboxStatus::class,
            'payload' => 'array',
            'available_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
