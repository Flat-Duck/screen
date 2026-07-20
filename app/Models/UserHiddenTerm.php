<?php

namespace App\Models;

use App\Enums\HiddenTermType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $original_value
 * @property string $normalized_value
 * @property string $normalized_hash
 * @property HiddenTermType $type
 */
class UserHiddenTerm extends Model
{
    protected $fillable = ['user_id', 'original_value', 'normalized_value', 'normalized_hash', 'type'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['original_value' => 'encrypted', 'type' => HiddenTermType::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
