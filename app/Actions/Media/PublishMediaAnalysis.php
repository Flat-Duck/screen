<?php

namespace App\Actions\Media;

use App\Actions\Posts\SyncPostHashtags;
use App\Actions\Posts\SyncPostMentions;
use App\Jobs\ComputePostMediaPerceptualHash;
use App\Jobs\GeneratePostMediaThumbnail;
use App\Models\Group;
use App\Models\MediaAnalysis;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\GroupService;
use App\Services\Screenshots\CategoryMatcher;
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
                    'safety_status' => $item->safety_status,
                    'safety_version' => $item->analysis_version,
                ]);
                GeneratePostMediaThumbnail::dispatch($media->id)->afterCommit();
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
}
