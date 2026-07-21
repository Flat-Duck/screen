<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Moderator = 'moderator';
    case Support = 'support';
    case TelemetryViewer = 'telemetry_viewer';
    case ReadOnlyAuditor = 'read_only_auditor';

    public function can(string $permission): bool
    {
        return match ($this) {
            self::SuperAdmin => true,
            self::Moderator => in_array($permission, ['dashboard.view', 'moderation.view', 'moderation.manage', 'users.view'], true),
            self::Support => in_array($permission, ['dashboard.view', 'users.view', 'users.support'], true),
            self::TelemetryViewer => in_array($permission, ['dashboard.view', 'telemetry.view', 'operations.view'], true),
            self::ReadOnlyAuditor => in_array($permission, ['dashboard.view', 'moderation.view', 'users.view', 'audit.view'], true),
        };
    }
}
