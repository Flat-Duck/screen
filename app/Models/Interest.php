<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Interest extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @param Builder<Interest> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsToMany<ScreenshotCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ScreenshotCategory::class, 'interest_category', 'interest_id', 'category_id')->withPivot('weight');
    }

    /** @return BelongsToMany<Hashtag, $this> */
    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'hashtag_interest')->withPivot('weight');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['weight', 'source', 'selected_at'])->withTimestamps();
    }
}
