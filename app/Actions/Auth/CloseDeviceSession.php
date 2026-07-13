<?php

namespace App\Actions\Auth;

use App\Enums\SessionEndReason;
use App\Models\DeviceSession;
use Illuminate\Support\Facades\DB;

final class CloseDeviceSession
{
    public function __invoke(DeviceSession $session, SessionEndReason $reason): void
    {
        DB::transaction(function () use ($session, $reason): void {
            $session = DeviceSession::query()->with(['device', 'accessToken'])->lockForUpdate()->find($session->id);

            if (! $session || $session->ended_at !== null) {
                return;
            }

            $token = $session->accessToken;
            $session->forceFill([
                'personal_access_token_id' => null,
                'ended_at' => now(),
                'end_reason' => $reason,
                'revoked_at' => $reason === SessionEndReason::Expired ? null : now(),
            ])->save();
            $token?->delete();

            $hasAnotherActiveSession = DeviceSession::query()
                ->where('device_id', $session->device_id)
                ->whereNull('ended_at')
                ->exists();

            if (! $hasAnotherActiveSession && $session->device->user_id === $session->user_id) {
                $session->device->forceFill(['user_id' => null])->save();
            }
        });
    }
}
