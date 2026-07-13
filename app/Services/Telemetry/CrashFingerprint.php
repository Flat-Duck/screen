<?php

namespace App\Services\Telemetry;

final class CrashFingerprint
{
    public function make(?string $exceptionClass, ?string $stackTrace): ?string
    {
        if ($exceptionClass === null && $stackTrace === null) {
            return null;
        }

        $frames = array_slice(preg_split('/\R/', $stackTrace ?? '') ?: [], 0, 6);
        $normalized = array_map(
            static fn (string $frame): string => preg_replace('/:\d+\)?$/', ':#)', trim($frame)) ?? trim($frame),
            $frames,
        );

        return hash('sha256', ($exceptionClass ?? 'unknown').'|'.implode('|', $normalized));
    }
}
