<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentFilterMatch extends Model
{
    protected $fillable = ['user_id', 'hidden_term_id', 'filterable_type', 'filterable_id', 'reason'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function filterable(): MorphTo
    {
        return $this->morphTo();
    }
}
