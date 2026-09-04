<?php

namespace App\Enums;

enum OcrLabelVerdict: string
{
    /** Everything legible in the image came through, near enough to be usable. */
    case Correct = 'correct';

    /** Some real text was captured, some was missed or mangled. */
    case Partial = 'partial';

    /** The extraction bears no useful relation to what is in the image. */
    case Wrong = 'wrong';

    /**
     * The image genuinely has no legible text, so empty output is right. Kept separate from
     * Correct: lumping them together lets a language the engine cannot read hide as success,
     * because "found nothing" and "there was nothing" would score identically.
     */
    case NoTextInImage = 'no_text_in_image';

    public function label(): string
    {
        return match ($this) {
            self::Correct => 'Correct',
            self::Partial => 'Partially correct',
            self::Wrong => 'Wrong',
            self::NoTextInImage => 'No text in the image',
        };
    }

    /** Whether the engine did the right thing — the numerator of labelled accuracy. */
    public function isSuccess(): bool
    {
        return $this === self::Correct || $this === self::NoTextInImage;
    }
}
