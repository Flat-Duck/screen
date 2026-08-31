<?php

namespace App\Http\Controllers;

use App\Models\PrivateSave;
use App\Models\User;
use App\Support\Media\MediaDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PrivateSaveMediaDeliveryController extends Controller
{
    public function __invoke(Request $request, int $privateSave): Response
    {
        $viewer = User::query()->findOrFail((int) $request->query('viewer'));
        $save = PrivateSave::query()->findOrFail($privateSave);
        abort_unless($save->user_id === $viewer->id, 404);

        $disk = Storage::disk($save->sourceDisk());
        abort_unless($disk->exists($save->path), 404);

        return MediaDelivery::respond($disk, $save->path, 'no-store, private', [
            'Content-Type' => $save->mime_type,
        ]);
    }
}
