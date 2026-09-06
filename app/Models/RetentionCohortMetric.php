<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetentionCohortMetric extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'cohort_date', 'activity_date', 'day_number', 'cohort_size', 'retained_users',
        'is_partial',
    ];

    protected function casts(): array
    {
        return ['cohort_date' => 'date', 'activity_date' => 'date', 'is_partial' => 'boolean'];
    }
}
