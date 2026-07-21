<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAuthorAffinity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['affinity_date' => 'date', 'last_event_at' => 'datetime'];
    }
}
