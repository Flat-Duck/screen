<?php

namespace App\Notifications\Contracts;

/** Implemented by any Notification that also wants to be delivered as a push via FcmChannel. */
interface FcmNotification
{
    /**
     * @return array{title: string, body: string, data?: array<string, string>}
     */
    public function toFcm(object $notifiable): array;
}
