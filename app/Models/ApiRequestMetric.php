<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class ApiRequestMetric extends Model
{
    use MassPrunable;

    /** @var list<string> */
    protected $fillable = [
        'minute', 'requests', 'errors', 'rate_limited', 'total_duration_ms', 'max_duration_ms',
    ];

    protected function casts(): array
    {
        return ['minute' => 'datetime'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('minute', '<', now()->subDays(30));
    }
}
