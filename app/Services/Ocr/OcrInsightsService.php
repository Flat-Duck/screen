<?php

namespace App\Services\Ocr;

use App\Models\OcrVerification;
use App\Models\PostMedia;
use App\Models\UserOcrTrust;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the OCR dashboard.
 *
 * Reads counts, statuses and scores only — never OCR text. The text is encrypted at rest and
 * routinely contains credentials and IDs; nothing here needs it, so nothing here decrypts it.
 */
class OcrInsightsService
{
    /** @return array<string, mixed> */
    public function pipeline(int $withinDays = 30): array
    {
        $since = now()->subDays($withinDays);

        /** @var array<string, int> $statuses */
        $statuses = PostMedia::query()
            ->where('created_at', '>=', $since)
            ->groupBy('ocr_status')
            ->pluck(DB::raw('count(*)'), 'ocr_status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $total = array_sum($statuses);
        // Only rows the server actually extracted can be judged on outcome. A `skipped` row
        // never ran, so counting it as a success or an empty result is equally wrong.
        $ran = ($statuses[PostMedia::PROCESSING_READY] ?? 0) + ($statuses[PostMedia::PROCESSING_FAILED] ?? 0);
        $ready = $statuses[PostMedia::PROCESSING_READY] ?? 0;

        $emptyText = PostMedia::query()
            ->where('created_at', '>=', $since)
            ->where('ocr_status', PostMedia::PROCESSING_READY)
            ->whereNull('ocr_text')
            ->count();

        return [
            'window_days' => $withinDays,
            'total' => $total,
            'statuses' => $statuses,
            'ran' => $ran,
            'never_ran' => $statuses[PostMedia::PROCESSING_SKIPPED] ?? 0,
            'failure_rate' => $ran === 0 ? null : round((($statuses[PostMedia::PROCESSING_FAILED] ?? 0) / $ran) * 100, 2),
            // A high empty rate on a screenshot app usually means a language the engine
            // cannot read, not images without text — see the Arabic note in config/social.php.
            'empty_text_rate' => $ready === 0 ? null : round(($emptyText / $ready) * 100, 2),
            'durations' => $this->durationPercentiles($since),
            'sources' => $this->groupedCounts('ocr_source', $since),
            'languages' => $this->groupedCounts('ocr_language', $since),
            'versions' => $this->groupedCounts('ocr_version', $since),
            'safety' => $this->groupedCounts('safety_status', $since),
        ];
    }

    /**
     * Device-vs-server agreement. Everything here comes from `ocr_verifications`, which is
     * why it exists: the comparison used to be discarded at publish.
     *
     * @return array<string, mixed>
     */
    public function accuracy(int $withinDays = 90): array
    {
        $since = now()->subDays($withinDays);
        $compared = OcrVerification::query()->compared()->where('created_at', '>=', $since);

        $matches = (clone $compared)->where('verdict', OcrVerification::VERDICT_MATCH)->count();
        $total = (clone $compared)->count();
        $unverified = OcrVerification::query()
            ->where('verdict', OcrVerification::VERDICT_UNVERIFIED)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'window_days' => $withinDays,
            'compared' => $total,
            'matches' => $matches,
            'mismatches' => $total - $matches,
            // Category agreement — what the trust loop actually acts on.
            'agreement_rate' => $total === 0 ? null : round(($matches / $total) * 100, 2),
            // Token agreement — what the text quality actually is. These two diverging is the
            // interesting signal: it means the category test is passing texts that differ.
            'mean_similarity' => $total === 0 ? null : round((float) (clone $compared)->avg('similarity') * 100, 2),
            'unverified' => $unverified,
            'unverified_share' => ($total + $unverified) === 0
                ? null
                : round(($unverified / ($total + $unverified)) * 100, 2),
            'trust_tiers' => UserOcrTrust::query()
                ->groupBy('trust_tier')
                ->pluck(DB::raw('count(*)'), 'trust_tier')
                ->map(fn ($count): int => (int) $count)
                ->all(),
        ];
    }

    /**
     * The learning curve: agreement and similarity per week. Weekly rather than daily because
     * sampling is sparse by design (8% for a trusted account), and a daily series over that
     * is mostly noise.
     *
     * @return list<array{bucket: string, compared: int, matches: int, agreement: float|null, similarity: float|null}>
     */
    public function curve(int $weeks = 12): array
    {
        $since = now()->subWeeks($weeks)->startOfWeek();

        // Postgres in production, SQLite in tests — one expression each, never both, or the
        // query ends up with two columns named `bucket`.
        $bucket = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(date_trunc('week', created_at), 'IYYY-IW')"
            : "strftime('%Y-%W', created_at)";

        // The query builder rather than the model: these are aggregate rows, not
        // OcrVerification records, and typing them as such would be a lie.
        $rows = DB::table('ocr_verifications')
            ->whereIn('verdict', [OcrVerification::VERDICT_MATCH, OcrVerification::VERDICT_MISMATCH])
            ->where('created_at', '>=', $since)
            ->selectRaw($bucket.' as bucket')
            ->selectRaw('count(*) as compared')
            ->selectRaw("sum(case when verdict = 'match' then 1 else 0 end) as matches")
            ->selectRaw('avg(similarity) as mean_similarity')
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->get()
            ->map(function (object $row): array {
                $compared = (int) $row->compared;
                $matches = (int) $row->matches;

                return [
                    'bucket' => (string) $row->bucket,
                    'compared' => $compared,
                    'matches' => $matches,
                    'agreement' => $compared === 0 ? null : round(($matches / $compared) * 100, 1),
                    'similarity' => $row->mean_similarity === null ? null : round((float) $row->mean_similarity * 100, 1),
                ];
            })
            ->all();

        return array_values($rows);
    }

    /**
     * @return array<string, int>
     */
    private function groupedCounts(string $column, CarbonInterface $since): array
    {
        /** @var array<string, int> $counts */
        $counts = PostMedia::query()
            ->where('created_at', '>=', $since)
            ->groupBy($column)
            ->pluck(DB::raw('count(*)'), $column)
            ->mapWithKeys(fn ($count, $key): array => [((string) $key) === '' ? 'unknown' : (string) $key => (int) $count])
            ->all();

        arsort($counts);

        return $counts;
    }

    /**
     * Percentiles in PHP rather than SQL: the two drivers in play disagree on percentile
     * syntax, and the row count here is small enough that it does not matter.
     *
     * @return array{p50: int|null, p95: int|null, max: int|null, samples: int}
     */
    private function durationPercentiles(CarbonInterface $since): array
    {
        $durations = PostMedia::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('ocr_duration_ms')
            ->orderBy('ocr_duration_ms')
            ->pluck('ocr_duration_ms')
            ->map(fn ($value): int => (int) $value)
            ->values();

        $count = $durations->count();

        if ($count === 0) {
            return ['p50' => null, 'p95' => null, 'max' => null, 'samples' => 0];
        }

        $at = fn (float $fraction): int => (int) $durations[min($count - 1, (int) floor($fraction * $count))];

        return ['p50' => $at(0.50), 'p95' => $at(0.95), 'max' => (int) $durations->last(), 'samples' => $count];
    }
}
