<?php

namespace App\Notifications\Contracts;

/** Implemented by any Notification that also wants to be delivered as a push via FcmChannel. */
interface FcmNotification
{
    /**
     * @return array{title: string, body: string, data?: array<string, string>}
     */
    public function toFcm(object $notifiable): array;

    /**
     * The `notifications.*` key in SettingsService that gates this notification's push
     * delivery — database/in-app delivery is never gated, only FcmChannel checks this.
     */
    public function settingsKey(): string;
}
