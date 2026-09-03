<?php

namespace App\Enums;

enum HashtagModerationState: string
{
    /** Normal. Rankable, searchable, browsable. */
    case Clear = 'clear';

    /**
     * Suppressed from trending, explore and search, but the tag page still resolves and
     * its posts stay visible — the reach is removed, the speech is not.
     */
    case NotRecommended = 'not_recommended';

    /** Fully withheld: also 404s its own tag page. Posts carrying it are untouched. */
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Clear',
            self::NotRecommended => 'Not recommended',
            self::Blocked => 'Blocked',
        };
    }

    /** Whether a tag in this state may appear in trending, explore or search results. */
    public function isDiscoverable(): bool
    {
        return $this === self::Clear;
    }
}
