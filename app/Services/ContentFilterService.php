<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Message;
use App\Models\User;
use App\Models\UserHiddenTerm;

class ContentFilterService
{
    public function __construct(
        private readonly HiddenTermNormalizer $normalizer,
        private readonly SettingsService $settings,
    ) {}

    public function apply(Comment|Message $content, User $author, User $recipient, string $kind): bool
    {
        if ($author->is($recipient)) {
            return false;
        }

        $body = $content->body;
        $normalizedBody = $this->normalizer->normalize($body);
        $compactBody = $this->normalizer->compact($body);
        $matchedTerm = $this->matchingHiddenTerm($recipient, $normalizedBody, $compactBody);
        $reason = $matchedTerm !== null
            ? 'hidden_term'
            : $this->offensiveReason($recipient, $kind, $normalizedBody, $compactBody);

        if ($reason === null) {
            return false;
        }

        $content->filterMatches()->updateOrCreate(
            ['user_id' => $recipient->id],
            ['reason' => $reason, 'hidden_term_id' => $matchedTerm?->id],
        );

        return true;
    }

    private function matchingHiddenTerm(User $recipient, string $normalizedBody, string $compactBody): ?UserHiddenTerm
    {
        foreach ($recipient->hiddenTerms()->get() as $term) {
            if (str_contains($normalizedBody, $term->normalized_value)
                || str_contains($compactBody, str_replace(' ', '', $term->normalized_value))) {
                return $term;
            }
        }

        return null;
    }

    private function offensiveReason(User $recipient, string $kind, string $normalizedBody, string $compactBody): ?string
    {
        $enabledKey = $kind === 'comment' ? 'hide_offensive_comments' : 'hide_offensive_messages';
        $enabled = (bool) ($this->settings->getFor($recipient)['content_filters'][$enabledKey] ?? false);

        if (! $enabled) {
            return null;
        }

        foreach (config('social.offensive_terms', []) as $term) {
            $normalizedTerm = $this->normalizer->normalize((string) $term);
            if ($normalizedTerm !== '' && (str_contains($normalizedBody, $normalizedTerm)
                || str_contains($compactBody, str_replace(' ', '', $normalizedTerm)))) {
                return 'offensive';
            }
        }

        return null;
    }
}
