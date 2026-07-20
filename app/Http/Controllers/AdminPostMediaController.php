<?php

namespace App\Http\Controllers;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPostMediaController extends Controller
{
    public function __invoke(PostMedia $media): StreamedResponse
    {
        $disk = Storage::disk(config('social.media_disk'));
        abort_unless($disk->exists($media->original_path), 404);

        return response()->stream(function () use ($disk, $media): void {
            $stream = $disk->readStream($media->original_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'no-store, private']);
    }
}
