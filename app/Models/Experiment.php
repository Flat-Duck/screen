<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string, int> $variants
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class Experiment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'kill_switch' => 'boolean',
            'allocation_basis_points' => 'integer',
            'variants' => 'array',
            'version' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_enabled
            && ! $this->kill_switch
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gt(now()));
    }

    /** @return HasMany<ExperimentAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ExperimentAssignment::class);
    }
}
