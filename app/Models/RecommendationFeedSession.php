<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationFeedSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'request_id', 'user_id', 'ranking_version', 'items', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['items' => 'array', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
