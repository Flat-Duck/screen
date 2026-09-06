<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecommendationFeedbackAggregate extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'metric_date', 'surface', 'candidate_source', 'unique_users', 'impressions', 'opens',
        'saves', 'follows', 'hides', 'not_interested', 'reports',
    ];

    protected function casts(): array
    {
        return ['metric_date' => 'date'];
    }
}
