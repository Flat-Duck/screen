<?php

namespace App\Actions\Uploads;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareUpload
{
    /** @return array{upload: Upload, upload_url: string, headers: array<string, string>} */
    public function __invoke(User $user, string $contentType, int $byteSize): array
    {
        $uploadId = (string) Str::uuid();
        $extension = match ($contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $objectKey = "screenshots/{$user->id}/{$uploadId}.{$extension}";
        $expiresAt = now()->addSeconds((int) config('social.uploads.presign_ttl_seconds', 600));

        $disk = Storage::disk(config('social.uploads.disk', 'r2'));
        $signed = $disk->temporaryUploadUrl($objectKey, $expiresAt, [
            'ContentType' => $contentType,
        ]);

        $upload = Upload::create([
            'upload_id' => $uploadId,
            'user_id' => $user->id,
            'object_key' => $objectKey,
            // Not consumed by anything on this Phase 1 path — see the migration's comment on
            // this column. Generated now so it exists for Phase 4 without another migration.
            'nonce' => Str::random(43),
            'mime_type' => $contentType,
            'size_bytes' => $byteSize,
            'status' => Upload::STATUS_UPLOADING,
            'expires_at' => $expiresAt,
        ]);

        return [
            'upload' => $upload,
            'upload_url' => $signed['url'],
            'headers' => $signed['headers'] ?? [],
        ];
    }
}
