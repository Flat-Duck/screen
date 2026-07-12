<?php

namespace App\Contracts;

interface MediaFileStore
{
    /** @param list<string> $paths */
    public function deletePaths(array $paths): void;

    public function deleteDirectory(string $directory): void;
}
