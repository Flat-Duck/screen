<?php

namespace App\Models;

use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    public const SAFETY_CLEAR = 'clear';

    public const SAFETY_WARNING = 'warning';

    protected $fillable = [
        'post_id',
        'position',
        'original_path',
        'thumbnail_path',
        'width',
        'height',
        'mime_type',
        'size_bytes',
        'status',
        'alt_text',
        'ocr_text',
        'ocr_language',
        'ocr_status',
        'ocr_version',
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
            'ocr_text' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function originalUrl(): string
    {
        return Storage::disk(config('social.media_disk'))->url($this->original_path);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path
            ? Storage::disk(config('social.media_disk'))->url($this->thumbnail_path)
            : null;
    }
}
