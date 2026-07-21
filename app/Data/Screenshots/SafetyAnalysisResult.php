<?php

namespace App\Data\Screenshots;

final readonly class SafetyAnalysisResult
{
    /** @param list<array{category: string, region: array{x: float, y: float, width: float, height: float}}> $findings */
    public function __construct(public array $findings) {}

    public function hasWarnings(): bool
    {
        return $this->findings !== [];
    }
}
