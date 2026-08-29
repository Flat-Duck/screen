<?php

namespace App\Http\Controllers;

use App\Enums\AccountVisibility;
use App\Models\User;
use App\Services\BlockService;
use App\Support\Media\MediaDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class UserAvatarDeliveryController extends Controller
{
    public function __construct(private readonly BlockService $blocks) {}

    public function __invoke(Request $request, int $user): Response
    {
        $viewer = User::query()->findOrFail((int) $request->query('viewer'));
        $profile = User::query()->findOrFail($user);
        abort_unless($profile->avatar_path !== null && $profile->isPubliclyVisible(), 404);
        abort_if($this->blocks->isBlockedEitherWay($viewer, $profile), 404);

        $disk = Storage::disk(config('social.media_disk'));
        abort_unless($disk->exists($profile->avatar_path), 404);
        $public = $profile->account_visibility === AccountVisibility::Public;

        return MediaDelivery::respond(
            $disk,
            $profile->avatar_path,
            $public ? $this->publicCacheControl() : 'no-store, private',
            ['Content-Type' => $this->imageMimeType($profile->avatar_path)],
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
