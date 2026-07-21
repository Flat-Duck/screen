<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationExclusion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @param Builder<RecommendationExclusion> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $window) => $window->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
