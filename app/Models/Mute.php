<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** No factory — tests create mutes via the API endpoint or plain DB inserts. */
class Mute extends Model
{
    protected $fillable = [
        'muter_id',
        'muted_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function muter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function muted(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_id');
    }
}
