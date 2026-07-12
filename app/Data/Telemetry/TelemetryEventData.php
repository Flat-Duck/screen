<?php

namespace App\Data\Telemetry;

final readonly class TelemetryEventData
{
    /**
     * @param  array<string, mixed>  $extras
     * @param  array<int, array<string, mixed>>  $breadcrumbs
     */
    public function __construct(
        public string $eventUuid,
        public string $kind,
        public string $name,
        public string $occurredAt,
        public array $extras,
        public array $breadcrumbs,
        public ?string $errorTag,
        public ?string $exceptionClass,
        public ?string $errorMessage,
        public ?string $stackTrace,
        public ?string $threadName,
        public ?bool $isFatal,
    ) {}
}
