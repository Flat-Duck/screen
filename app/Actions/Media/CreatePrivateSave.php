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
        $disk = (string) config('social.private_media_disk', 'local');
        $stored = $this->images->storeOriginal($image, $directory, diskName: $disk);

        return PrivateSave::create([
            'user_id' => $user->id,
            'path' => $stored['path'],
            'source_disk' => $disk,
            'width' => $stored['width'],
            'height' => $stored['height'],
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
        ]);
    }
}
