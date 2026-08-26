<?php

namespace App\Contracts;

interface PerceptualHasher
{
    public function hash(string $disk, string $path): string;

    public function version(): string;
}
