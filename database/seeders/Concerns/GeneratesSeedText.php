<?php

namespace Database\Seeders\Concerns;

trait GeneratesSeedText
{
    /** @var list<string> */
    private const WORDS = [
        'screenshot', 'design', 'screen', 'pixel', 'rendering', 'gesture', 'widget', 'layout', 'theme', 'palette',
        'workflow', 'feedback', 'sharing', 'comment', 'repost', 'like', 'collection', 'category', 'hashtag', 'feed',
        'notifications', 'conversation', 'message', 'moderation', 'report', 'restriction', 'analytics', 'insight', 'metric', 'cohort',
        'experiment', 'variant', 'control', 'ranking', 'candidate', 'affinity', 'interest', 'wellness', 'productivity', 'inspiration',
        'creative', 'lightweight', 'responsive', 'adaptive', 'authentic', 'engaging', 'consistent', 'polished', 'thoughtful', 'useful',
    ];

    private function sentence(int $words): string
    {
        return ucfirst(implode(' ', array_map(
            fn (): string => self::WORDS[random_int(0, count(self::WORDS) - 1)],
            range(1, max(1, $words))
        ))).'.';
    }

    private function optionalSentence(): ?string
    {
        return random_int(0, 1) === 0 ? null : $this->sentence(random_int(3, 6));
    }
}
