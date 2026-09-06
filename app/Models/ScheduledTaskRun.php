<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledTaskRun extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'task_key', 'task_name', 'status', 'runtime_ms', 'last_started_at', 'last_succeeded_at',
        'last_failed_at', 'last_error_class',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }
}
