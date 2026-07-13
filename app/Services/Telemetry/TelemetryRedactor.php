<?php

namespace App\Services\Telemetry;

final class TelemetryRedactor
{
    private const SENSITIVE_KEYS = '/authorization|token|password|secret|email|caption|file_?path|private_?path/i';

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function redactArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEYS, $key) === 1) {
                $value[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $value[$key] = $this->redactArray($item);
            } elseif (is_string($item)) {
                $value[$key] = $this->redactString($item);
            }
        }

        return $value;
    }

    public function redactString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $value) ?? $value;

        return preg_replace('/\/(?:data|storage|private)\/[^\s]+/i', '/[REDACTED_PATH]', $value) ?? $value;
    }
}
