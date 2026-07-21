<?php

namespace App\Contracts;

use App\Data\Recommendations\RecommendationCandidate;
use App\Enums\CandidateSource;
use App\Models\User;
use Illuminate\Support\Collection;

interface RecommendationCandidateSource
{
    public function source(): CandidateSource;

    /** @return Collection<int, RecommendationCandidate> */
    public function generate(User $viewer, int $limit): Collection;
}
