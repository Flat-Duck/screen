<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyUserActivity extends Model
{
    protected $table = 'daily_user_activity';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activity_date' => 'date'];
    }
}
