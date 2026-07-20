<?php

namespace App\Services;

use Normalizer;

class HiddenTermNormalizer
{
    public function normalize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $value = mb_strtolower($value);
        $value = strtr($value, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public function compact(string $value): string
    {
        return str_replace(' ', '', $this->normalize($value));
    }
}
