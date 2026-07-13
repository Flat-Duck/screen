<?php

namespace App\Actions\Auth;

use App\Enums\SessionEndReason;
use App\Models\DeviceSession;

final class ExpireDeviceSessions
{
    public function __construct(private readonly CloseDeviceSession $closeSession) {}

    public function __invoke(): int
    {
        $expired = 0;

        foreach (DeviceSession::query()
            ->whereNull('ended_at')
            ->where(function ($query): void {
                $query->whereNull('personal_access_token_id')
                    ->orWhereHas('accessToken', fn ($token) => $token->where('expires_at', '<=', now()));
            })
            ->select('id')
            ->lazyById(100) as $candidate) {
            $session = DeviceSession::find($candidate->id);

            if ($session && $session->ended_at === null) {
                ($this->closeSession)($session, SessionEndReason::Expired);
                $expired++;
            }
        }

        return $expired;
    }
}
