<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTopicAffinity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'affinity_date', 'user_id', 'category_id', 'score', 'positive_events',
        'negative_events', 'impressions', 'last_event_at',
    ];

    protected function casts(): array
    {
        return ['affinity_date' => 'date', 'last_event_at' => 'datetime'];
    }
}
