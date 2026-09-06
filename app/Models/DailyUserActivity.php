<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyUserActivity extends Model
{
    protected $table = 'daily_user_activity';

    /** @var list<string> */
    protected $fillable = [
        'activity_date', 'user_id', 'events_count', 'unique_posts', 'impressions', 'opens',
        'dwell_ms', 'likes', 'comments', 'saves', 'reposts', 'shares', 'follows',
        'negative_feedback',
    ];

    protected function casts(): array
    {
        return ['activity_date' => 'date'];
    }
}
