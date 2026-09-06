<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentAssignment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'experiment_id', 'user_id', 'variant', 'experiment_version', 'assigned_at',
    ];

    protected function casts(): array
    {
        return ['experiment_version' => 'integer', 'assigned_at' => 'datetime'];
    }

    /** @return BelongsTo<Experiment, $this> */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
