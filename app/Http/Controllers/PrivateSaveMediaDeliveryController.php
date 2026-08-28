<?php

namespace App\Http\Controllers;

use App\Models\PrivateSave;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateSaveMediaDeliveryController extends Controller
{
    public function __invoke(Request $request, int $privateSave): StreamedResponse
    {
        $viewer = User::query()->findOrFail((int) $request->query('viewer'));
        $save = PrivateSave::query()->findOrFail($privateSave);
        abort_unless($save->user_id === $viewer->id, 404);

        $disk = Storage::disk($save->sourceDisk());
        abort_unless($disk->exists($save->path), 404);

        return $disk->response($save->path, null, [
            'Content-Type' => $save->mime_type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
