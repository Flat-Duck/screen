<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedCollection extends Model
{
    protected $table = 'collections';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'name', 'description', 'position', 'visibility', 'version',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class, 'collection_id')->orderBy('position');
    }
}
