<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use App\Support\Media\MediaDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class GroupPhotoDeliveryController extends Controller
{
    public function __invoke(Request $request, int $group): Response
    {
        $viewer = User::query()->findOrFail((int) $request->query('viewer'));
        $groupModel = Group::query()->findOrFail($group);
        abort_unless($groupModel->photo_path !== null, 404);
        abort_unless($groupModel->visibility !== 'private' || $groupModel->isMember($viewer), 404);

        $disk = Storage::disk(config('social.media_disk'));
        abort_unless($disk->exists($groupModel->photo_path), 404);

        return MediaDelivery::respond(
            $disk,
            $groupModel->photo_path,
            $groupModel->visibility === 'public'
                ? $this->publicCacheControl()
                : 'no-store, private',
            ['Content-Type' => $this->imageMimeType($groupModel->photo_path)],
        );
    }

    private function publicCacheControl(): string
    {
        $seconds = min(
            (int) config('social.public_media_cache_seconds', 300),
            (int) config('social.media_url_ttl_seconds', 1200),
        );

        return 'public, max-age='.max(0, $seconds).', must-revalidate';
    }

    private function imageMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
