<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $status
 * @property array<string, array{status: string}> $checks
 * @property array<string, mixed> $metrics
 * @property CarbonImmutable $captured_at
 */
class OperationsHealthSnapshot extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'status', 'checks', 'metrics', 'captured_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['checks' => 'array', 'metrics' => 'array', 'captured_at' => 'datetime'];
    }
}
