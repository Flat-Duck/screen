<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyProductMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metric_date' => 'date', 'is_partial' => 'boolean', 'aggregated_at' => 'datetime'];
    }
}
