<?php

namespace App\Contracts;

interface PerceptualHasher
{
    public function hash(string $path): string;

    public function version(): string;
}
