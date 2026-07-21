<?php

namespace App\Services;

use App\Models\CrashGroup;
use App\Models\TelemetryEvent;
use Illuminate\Support\Facades\DB;

class CrashGroupSynchronizer
{
    public function sync(TelemetryEvent $event): void
    {
        if ($event->crash_fingerprint === null || $event->crash_group_id !== null) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $group = CrashGroup::query()->firstOrCreate(['fingerprint' => $event->crash_fingerprint], [
                'name' => $event->name, 'exception_class' => $event->exception_class,
                'first_seen_at' => $event->occurred_at, 'last_seen_at' => $event->occurred_at,
            ]);
            $group = CrashGroup::query()->lockForUpdate()->findOrFail($group->id);
            $event->update(['crash_group_id' => $group->id]);
            $newUser = $event->user_id !== null && DB::table('crash_group_users')->insertOrIgnore(['crash_group_id' => $group->id, 'user_id' => $event->user_id]) === 1;
            $group->forceFill([
                'occurrence_count' => $group->occurrence_count + 1,
                'affected_user_count' => $group->affected_user_count + ($newUser ? 1 : 0),
                'first_seen_at' => $group->first_seen_at->min($event->occurred_at),
                'last_seen_at' => $group->last_seen_at->max($event->occurred_at),
            ])->save();
        });
    }
}
