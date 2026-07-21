<?php

namespace App\Models;

use App\Enums\CandidateSource;
use App\Enums\ContentEventType;
use App\Enums\ContentSurface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_type' => ContentEventType::class,
            'surface' => ContentSurface::class,
            'candidate_source' => CandidateSource::class,
            'position' => 'integer',
            'experiment_assignments' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
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

    /** @return BelongsTo<DeviceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class, 'device_session_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
