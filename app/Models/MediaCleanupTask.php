<?php

namespace App\Models;

use App\Enums\MediaCleanupStatus;
use Illuminate\Database\Eloquent\Model;

class MediaCleanupTask extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'directory', 'status', 'attempts', 'available_at', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MediaCleanupStatus::class,
            'available_at' => 'datetime',
        ];
    }
}
