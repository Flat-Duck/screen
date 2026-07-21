<?php

namespace App\Actions\Media;

use App\Contracts\MediaFileStore;
use App\Models\MediaAnalysis;
use Illuminate\Support\Facades\DB;

class DeleteMediaAnalysis
{
    public function __construct(private readonly MediaFileStore $files) {}

    public function __invoke(MediaAnalysis $analysis): void
    {
        $this->files->deleteDirectory($analysis->directory);

        DB::transaction(function () use ($analysis): void {
            if ($analysis->cleanup_task_id !== null) {
                DB::table('media_cleanup_tasks')->where('id', $analysis->cleanup_task_id)->delete();
            }
            $analysis->delete();
        });
    }
}
