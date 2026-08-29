<?php

namespace App\Actions\Uploads;

use App\Models\Upload;
use App\Services\ImageSafetyInspector;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CommitUpload
{
    public function __construct(private readonly ImageSafetyInspector $inspector) {}

    public function __invoke(Upload $upload, string $nonce, int $protocolVersion, string $imageSha256, ?string $ocrText): Upload
    {
        if ($upload->isExpired()) {
            abort(410, 'This upload has expired.');
        }
        if ($upload->status !== Upload::STATUS_UPLOADING) {
            abort(409, 'This upload has already been committed.');
        }
        if (! hash_equals($upload->nonce, $nonce) || $upload->protocol_version !== $protocolVersion) {
            abort(422, 'The upload commit binding is invalid.');
        }

        $disk = Storage::disk(config('social.uploads.disk', 'r2'));

        if (! $disk->exists($upload->object_key)) {
            $upload->update(['status' => Upload::STATUS_REJECTED]);
            abort(422, 'The uploaded object could not be found in storage.');
        }

        try {
            $actual = $this->inspector->inspectObject($disk, $upload->object_key);
        } catch (InvalidArgumentException $exception) {
            $upload->update(['status' => Upload::STATUS_REJECTED]);
            abort(422, $exception->getMessage());
        }

        if ($upload->size_bytes !== $actual['size']
            || $upload->mime_type !== $actual['mime']
            || ! hash_equals(strtolower($imageSha256), $actual['sha256'])) {
            $upload->update(['status' => Upload::STATUS_REJECTED]);
            abort(422, 'The uploaded object does not match its declared size, type, or hash.');
        }

        $upload->update([
            'image_sha256' => $actual['sha256'],
            'width' => $actual['width'],
            'height' => $actual['height'],
            'ocr_text' => $ocrText,
            'status' => Upload::STATUS_UPLOADED,
        ]);

        // TODO(Phase 2): sample this upload for server-side OCR re-verification against
        // $ocrText via CategoryMatcher instead of trusting it outright — see
        // docs/SECURITY.md §4. Every commit is unconditionally trusted for now.

        return $upload->refresh();
    }
}
