<?php

namespace App\Actions\Uploads;

use App\Models\Upload;
use Illuminate\Support\Facades\Storage;

class CommitUpload
{
    public function __invoke(Upload $upload, string $imageSha256, ?string $ocrText): Upload
    {
        if ($upload->isExpired()) {
            abort(410, 'This upload has expired.');
        }
        if ($upload->status !== Upload::STATUS_UPLOADING) {
            abort(409, 'This upload has already been committed.');
        }

        $disk = Storage::disk(config('social.uploads.disk', 'r2'));

        if (! $disk->exists($upload->object_key)) {
            $upload->update(['status' => Upload::STATUS_REJECTED]);
            abort(422, 'The uploaded object could not be found in storage.');
        }

        // The client declared a size at prepare() time, before it had ever touched R2 — a
        // mismatch against what actually landed means either a different file was substituted
        // or the upload is otherwise not what it claims to be. Cheap integrity check even before
        // any signature/hash-binding work (Phase 4) exists.
        $actualSize = $disk->size($upload->object_key);
        if ($upload->size_bytes !== null && $actualSize !== $upload->size_bytes) {
            $upload->update(['status' => Upload::STATUS_REJECTED]);
            abort(422, 'The uploaded object size does not match what was declared.');
        }

        $upload->update([
            'image_sha256' => $imageSha256,
            'ocr_text' => $ocrText,
            'status' => Upload::STATUS_UPLOADED,
        ]);

        // TODO(Phase 2): sample this upload for server-side OCR re-verification against
        // $ocrText via CategoryMatcher instead of trusting it outright — see
        // docs/SECURITY.md §4. Every commit is unconditionally trusted for now.

        return $upload->refresh();
    }
}
