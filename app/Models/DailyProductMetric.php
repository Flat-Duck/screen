<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyProductMetric extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'metric_date', 'daily_active_users', 'registrations', 'active_creators',
        'screenshots_published', 'impressions', 'opens', 'saves', 'follows', 'hides', 'reports',
        'sessions_started', 'crashed_sessions', 'is_partial', 'aggregated_at',
    ];

    protected function casts(): array
    {
        return ['metric_date' => 'date', 'is_partial' => 'boolean', 'aggregated_at' => 'datetime'];
    }
}
