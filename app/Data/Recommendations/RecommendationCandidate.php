<?php

namespace App\Data\Recommendations;

use App\Enums\CandidateSource;
use Carbon\CarbonInterface;

final readonly class RecommendationCandidate
{
    /** @param array<string, mixed> $eligibility */
    public function __construct(
        public int $postId,
        public CandidateSource $source,
        public float $sourceScore,
        public CarbonInterface $generatedAt,
        public array $eligibility,
    ) {}
}
