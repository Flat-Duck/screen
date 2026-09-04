<?php

namespace App\Actions\Media;

use App\Actions\Posts\SyncPostHashtags;
use App\Actions\Posts\SyncPostMentions;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Group;
use App\Models\MediaAnalysis;
use App\Models\MediaAnalysisItem;
use App\Models\OcrVerification;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Upload;
use App\Models\User;
use App\Services\GroupService;
use App\Services\Screenshots\CategoryMatcher;
use App\Services\Screenshots\OcrTextSimilarity;
use App\Services\Screenshots\OcrTrustSampler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

/**
 * The composer sends only `caption`/`acknowledge_sensitive`/`alt_text`/`source_*`/`group_id` now
 * — `category_id` and `content_warning` are computed here, not client input (see
 * {@see CategoryMatcher} and the `$hasWarnings` check below), per the 2026-08 "the client only
 * picks the image and writes the words, everything else is the backend's job" redesign.
 */
class PublishMediaAnalysis
{
    public function __construct(
        private readonly SyncPostHashtags $syncHashtags,
        private readonly SyncPostMentions $syncMentions,
        private readonly CategoryMatcher $categoryMatcher,
        private readonly GroupService $groups,
        private readonly OcrTrustSampler $sampler,
        private readonly OcrTextSimilarity $similarity,
    ) {}

    /** @param array<string, mixed> $data */
    public function __invoke(User $user, MediaAnalysis $analysis, array $data): Post
    {
        $post = DB::transaction(function () use ($user, $analysis, $data): Post {
            $locked = MediaAnalysis::query()->lockForUpdate()->with('items')->find($analysis->id);
            if (! $locked || $locked->user_id !== $user->id) {
                abort(404);
            }
            if ($locked->isExpired()) {
                throw new GoneHttpException('The media analysis has expired.');
            }
            if ($locked->status !== MediaAnalysis::STATUS_READY) {
                throw new ConflictHttpException('The media analysis is not ready to publish.');
            }

            $hasWarnings = $locked->items->contains(
                fn ($item): bool => $item->safety_status === PostMedia::SAFETY_WARNING,
            );
            if ($hasWarnings && ($data['acknowledge_sensitive'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'acknowledge_sensitive' => ['You must acknowledge sensitive-information warnings before publishing.'],
                ]);
            }

            $caption = $data['caption'] ?? null;
            $ocrText = $locked->items->pluck('ocr_text')->filter()->implode(' ');
            $category = $this->categoryMatcher->match($caption, $ocrText === '' ? null : $ocrText);

            // Resolve any sampled items now that a caption exists — comparing raw OCR strings
            // isn't reliable (formatting/whitespace differ even when both are right), so this
            // compares the same CategoryMatcher decision each text would have produced instead.
            // The server's own ocr_text (already canonical since AnalyzeStagedScreenshot ran) is
            // never touched by this — a mismatch corrects the trust tier, never the post itself.
            // See docs/SECURITY.md §4/§12.
            /** @var array<int, array<string, mixed>> $comparisons Keyed by item id, consumed below. */
            $comparisons = [];

            foreach ($locked->items->where('verification_status', 'pending') as $item) {
                $deviceCategory = $this->categoryMatcher->match($caption, $item->device_ocr_text);
                $serverCategory = $this->categoryMatcher->match($caption, $item->ocr_text);
                $matched = $deviceCategory?->id === $serverCategory?->id;
                $tierBefore = $this->sampler->tierFor($user);
                $item->update(['verification_status' => $matched ? 'verified_match' : 'verified_mismatch']);
                $matched ? $this->sampler->recordMatch($user) : $this->sampler->recordMismatch($user);

                // Captured now, written against the PostMedia row below: these items are
                // deleted a few lines later, taking the whole comparison with them.
                $comparisons[$item->id] = [
                    'verdict' => $matched ? OcrVerification::VERDICT_MATCH : OcrVerification::VERDICT_MISMATCH,
                    'category_matched' => $matched,
                    'device_category_id' => $deviceCategory?->id,
                    'server_category_id' => $serverCategory?->id,
                    'similarity' => $this->similarity->score($item->device_ocr_text, $item->ocr_text),
                    'trust_tier_before' => $tierBefore,
                    'trust_tier_after' => $this->sampler->tierFor($user),
                ];
            }

            $versions = $locked->items->pluck('analysis_version')->filter()->unique()->sort()->implode(',');
            $post = Post::create([
                'user_id' => $user->id,
                'caption' => $caption,
                'status' => Post::STATUS_PROCESSING,
                'comments_enabled' => $data['comments_enabled'] ?? true,
                'reposts_enabled' => $data['reposts_enabled'] ?? true,
                'category_id' => $category?->id,
                'source_application' => $data['source_application'] ?? null,
                'source_url' => $data['source_url'] ?? null,
                'content_warning' => $hasWarnings ? 'sensitive' : null,
                'safety_acknowledged_at' => $hasWarnings ? now() : null,
                'safety_analysis_version' => $versions === '' ? null : $versions,
            ]);
            ($this->syncHashtags)($post, $post->caption);

            // A client-sent alt_text override only makes sense for the (realistic, single-image)
            // composer case — with more than one staged image there's no way to know which one it
            // was meant for, so every item just falls back to its own OCR-derived suggestion.
            $altTextOverride = $locked->items->count() === 1 ? ($data['alt_text'] ?? null) : null;

            foreach ($locked->items as $item) {
                $media = $post->media()->create([
                    'position' => $item->position,
                    'original_path' => $item->original_path,
                    'source_disk' => $item->source_disk,
                    'width' => $item->width,
                    'height' => $item->height,
                    'mime_type' => $item->mime_type,
                    'size_bytes' => $item->size_bytes,
                    'status' => PostMedia::STATUS_PENDING,
                    'alt_text' => $altTextOverride ?? $item->suggestedAltText(),
                    'ocr_text' => $item->ocr_text,
                    'ocr_language' => $item->ocr_language,
                    'ocr_status' => $item->ocr_status,
                    'ocr_version' => $item->analysis_version,
                    'ocr_source' => $item->ocr_source,
                    'ocr_duration_ms' => $item->ocr_duration_ms,
                    'safety_status' => $item->safety_status,
                    'safety_version' => $item->analysis_version,
                ]);
                GeneratePostMediaThumbnail::dispatch($media->id)->afterCommit();
                $this->recordVerification($user, $media, $item, $comparisons[$item->id] ?? null);

                if ($item->upload_id !== null) {
                    Upload::whereKey($item->upload_id)->update(['status' => Upload::STATUS_PUBLISHED]);
                }
            }

            if (isset($data['group_id'])) {
                $group = Group::query()->findOrFail((int) $data['group_id']);
                $this->groups->shareIntoGroup($user, $group, $post);
            }

            if ($locked->cleanup_task_id !== null) {
                DB::table('media_cleanup_tasks')->where('id', $locked->cleanup_task_id)->delete();
            }
            $locked->delete();

            return $post->load(['media', 'user', 'category']);
        });

        ($this->syncMentions)($post, $post->caption);
        foreach ($post->media as $media) {
            ComputePostMediaPerceptualHash::dispatch($media->id);
        }

        return $post;
    }

    /**
     * Writes the permanent record of what the trust loop decided about this image.
     *
     * Only device-sourced items get a row. The server path has no device claim to compare
     * against, so an entry for it would say nothing and would dilute every agreement rate
     * computed over the table.
     *
     * An unsampled item still gets a row, with `unverified`: knowing how much device OCR is
     * being accepted without any check at all is exactly as important as the accuracy of the
     * part that is checked, and it is the number that tells you whether the 8% sample rate is
     * set sensibly.
     *
     * @param  array<string, mixed>|null  $comparison
     */
    private function recordVerification(User $user, PostMedia $media, MediaAnalysisItem $item, ?array $comparison): void
    {
        if ($item->ocr_source !== PostMedia::OCR_SOURCE_DEVICE) {
            return;
        }

        OcrVerification::create([
            'post_media_id' => $media->id,
            'user_id' => $user->id,
            'ocr_source' => $item->ocr_source,
            'verdict' => $comparison['verdict'] ?? OcrVerification::VERDICT_UNVERIFIED,
            // Hashes, never the text — this row outlives the post it describes.
            'device_text_hash' => $this->hash($item->device_ocr_text ?? $item->ocr_text),
            'server_text_hash' => $comparison === null ? null : $this->hash($item->ocr_text),
            'device_char_count' => $this->length($item->device_ocr_text ?? $item->ocr_text),
            'server_char_count' => $comparison === null ? null : $this->length($item->ocr_text),
            'similarity' => $comparison['similarity'] ?? null,
            'category_matched' => $comparison['category_matched'] ?? null,
            'device_category_id' => $comparison['device_category_id'] ?? null,
            'server_category_id' => $comparison['server_category_id'] ?? null,
            'engine_version' => $item->analysis_version,
            'ocr_language' => $item->ocr_language,
            'trust_tier_before' => $comparison['trust_tier_before'] ?? null,
            'trust_tier_after' => $comparison['trust_tier_after'] ?? null,
        ]);
    }

    private function hash(?string $text): ?string
    {
        return $text === null ? null : hash('sha256', $text);
    }

    private function length(?string $text): ?int
    {
        return $text === null ? null : mb_strlen($text);
    }
}
