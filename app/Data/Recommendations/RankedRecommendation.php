<?php

namespace App\Data\Recommendations;

final readonly class RankedRecommendation
{
    /**
     * @param  array<string, float>  $components
     * @param  array<string, mixed>  $eligibility
     */
    public function __construct(
        public int $postId,
        public string $source,
        public float $score,
        public string $reason,
        public array $components,
        public array $eligibility,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'post_id' => $this->postId,
            'source' => $this->source,
            'score' => $this->score,
            'reason' => $this->reason,
            'components' => $this->components,
            'eligibility' => $this->eligibility,
        ];
    }
}
