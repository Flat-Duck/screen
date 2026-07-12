<?php

namespace App\Models;

use App\Enums\MediaCleanupStatus;
use Illuminate\Database\Eloquent\Model;

class MediaCleanupTask extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MediaCleanupStatus::class,
            'available_at' => 'datetime',
        ];
    }
}
