<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<SavedCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(SavedCollection::class, 'collection_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
