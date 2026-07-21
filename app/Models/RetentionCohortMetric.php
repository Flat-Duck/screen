<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetentionCohortMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['cohort_date' => 'date', 'activity_date' => 'date', 'is_partial' => 'boolean'];
    }
}
