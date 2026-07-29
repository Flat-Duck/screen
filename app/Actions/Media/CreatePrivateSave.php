<?php

namespace App\Actions\Media;

use App\Models\PrivateSave;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Http\UploadedFile;

class CreatePrivateSave
{
    public function __construct(private readonly ImageProcessingService $images) {}

    public function __invoke(User $user, UploadedFile $image): PrivateSave
    {
        $directory = 'private-saves/'.$user->id;
        $stored = $this->images->storeOriginal($image, $directory);

        return PrivateSave::create([
            'user_id' => $user->id,
            'path' => $stored['path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
        ]);
    }
}
