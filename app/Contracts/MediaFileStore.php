<?php

namespace App\Contracts;

interface MediaFileStore
{
    /** @param list<string> $paths */
    public function deletePaths(array $paths, ?string $diskName = null): void;

    public function deleteDirectory(string $directory, ?string $diskName = null): void;
}
