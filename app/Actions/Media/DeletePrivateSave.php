<?php

namespace App\Actions\Media;

use App\Contracts\MediaFileStore;
use App\Models\PrivateSave;

class DeletePrivateSave
{
    public function __construct(private readonly MediaFileStore $files) {}

    public function __invoke(PrivateSave $privateSave): void
    {
        $this->files->deletePaths([$privateSave->path], $privateSave->sourceDisk());
        $privateSave->delete();
    }
}
