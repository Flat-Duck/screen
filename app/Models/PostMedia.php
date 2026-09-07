<?php

namespace App\Models;

use App\Jobs\UnpublishPostMediaFromCdn;
use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * One image within a Post's carousel. `original_path` is always servable immediately
 * (already EXIF/GPS-stripped synchronously on upload); `thumbnail_path` is populated
 * later by GeneratePostMediaThumbnail — `status` tracks that, not visibility.
 */
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const PROCESSING_PENDING = 'pending';

    public const PROCESSING_PROCESSING = 'processing';

    public const PROCESSING_READY = 'ready';

    public const PROCESSING_FAILED = 'failed';

    /**
     * OCR never ran for this row — seeded, imported, or taken from a trusted device claim.
     * Distinct from READY, which previously covered both "ran and found nothing" and "never
     * ran", making every success/empty rate computed over it meaningless.
     */
    public const PROCESSING_SKIPPED = 'skipped';

    public const OCR_SOURCE_SERVER = 'server';

    public const OCR_SOURCE_DEVICE = 'device';

    public const SAFETY_CLEAR = 'clear';

    public const SAFETY_WARNING = 'warning';

    protected $fillable = [
        'post_id',
        'position',
        'original_path',
        'source_disk',
        'thumbnail_path',
        'width',
        'height',
        'thumbhash',
        'public_token',
        'public_path',
        'public_thumbnail_path',
        'published_publicly_at',
        'mime_type',
        'size_bytes',
        'status',
        'alt_text',
        'ocr_text',
        'ocr_language',
        'ocr_status',
        'ocr_version',
        'ocr_source',
        'ocr_duration_ms',
        'perceptual_hash',
        'safety_status',
        'hash_status',
        'hash_version',
        'safety_version',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'ocr_duration_ms' => 'integer',
            'published_publicly_at' => 'datetime',
            'ocr_text' => 'encrypted',
        ];
    }

    /** @return HasMany<OcrLabel, $this> */
    public function ocrLabels(): HasMany
    {
        return $this->hasMany(OcrLabel::class, 'post_media_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function originalUrl(User $viewer): string
    {
        if (str_starts_with($this->original_path, 'https://') || str_starts_with($this->original_path, 'http://')) {
            return $this->original_path;
        }

        return $this->deliveryUrl('original', $viewer);
    }

    public function thumbnailUrl(User $viewer): ?string
    {
        if ($this->thumbnail_path && (str_starts_with($this->thumbnail_path, 'https://') || str_starts_with($this->thumbnail_path, 'http://'))) {
            return $this->thumbnail_path;
        }

        return $this->thumbnail_path
            ? $this->deliveryUrl('thumbnail', $viewer)
            : null;
    }

    public function sourceDisk(): string
    {
        return $this->source_disk ?? (string) config('social.media_disk');
    }

    private function deliveryUrl(string $variant, User $viewer): string
    {
        return $this->publicCdnUrl($variant) ?? URL::temporarySignedRoute(
            'media.posts.show',
            now()->addSeconds((int) config('social.media_url_ttl_seconds', 1200)),
            ['media' => $this->getKey(), 'variant' => $variant, 'viewer' => $viewer->getKey()],
        );
    }

    /**
     * The CDN URL for this variant, when one has been published and the feature is on.
     *
     * Keys off the stored `public_path` rather than re-evaluating visibility here, deliberately.
     * Two reasons: minting a URL happens once per media per response and must not add queries, and
     * it makes invalidation a *write* — {@see UnpublishPostMediaFromCdn} nulls these
     * columns — rather than a check that every read has to remember to perform. The corollary is
     * that those unpublish paths are load-bearing, with `media:reconcile-public` as the net.
     */
    public function publicCdnUrl(string $variant): ?string
    {
        if (! config('social.public_cdn.enabled')) {
            return null;
        }

        $path = $variant === 'thumbnail' ? $this->public_thumbnail_path : $this->public_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $base = config('filesystems.disks.'.config('social.public_cdn.disk').'.url');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /** Object keys under the public bucket. Unguessable, stable, and content-immutable. */
    public function publicObjectKey(string $variant): ?string
    {
        if (! is_string($this->public_token) || $this->public_token === '') {
            return null;
        }

        $extension = $variant === 'thumbnail'
            ? 'webp'
            : (pathinfo((string) $this->original_path, PATHINFO_EXTENSION) ?: 'jpg');

        return $this->public_token.'/'.($variant === 'thumbnail' ? 't' : 'o').'.'.$extension;
    }
}
