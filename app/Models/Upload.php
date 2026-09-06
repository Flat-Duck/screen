<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at */
class Upload extends Model
{
    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    protected $fillable = [
        'upload_id', 'user_id', 'device_id', 'object_key', 'nonce', 'image_sha256', 'width',
        'height', 'size_bytes', 'mime_type', 'status', 'ocr_text', 'expires_at',
        'protocol_version',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
