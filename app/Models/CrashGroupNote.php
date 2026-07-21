<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrashGroupNote extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<CrashGroup, $this> */
    public function crashGroup(): BelongsTo
    {
        return $this->belongsTo(CrashGroup::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
