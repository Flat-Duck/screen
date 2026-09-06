<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyPostMetric extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'metric_date', 'post_id', 'author_id', 'unique_viewers', 'impressions', 'opens',
        'dwell_ms', 'likes', 'comments', 'saves', 'reposts', 'shares', 'hides',
        'not_interested', 'reports',
    ];

    protected function casts(): array
    {
        return ['metric_date' => 'date'];
    }
}
