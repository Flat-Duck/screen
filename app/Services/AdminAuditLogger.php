<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AdminAuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(User $actor, string $action, ?Model $target = null, ?string $reason = null, ?array $before = null, ?array $after = null): AdminAuditLog
    {
        $request = request();
        $ip = $request->ip();

        return AdminAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'request_id' => $request->headers->get('X-Request-Id') ?: (string) Str::uuid(),
            'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
        ]);
    }
}
