# Interest onboarding and cold-start recommendations

New accounts are directed to a curated interest picker before the For You feed. The catalog uses
broad, non-sensitive screenshot topics and maps each choice to existing screenshot categories and
hashtags. Explicit choices are stored separately from inferred topic and author affinities.

## Mobile flow

1. Registration and login responses include `onboarding.interests` and `next_action`.
2. When `next_action` is `select_interests`, load `GET /api/v1/onboarding/interests`.
3. Submit 3–10 unique active IDs with `PUT /api/v1/me/interests`.
4. A visible skip action calls `POST /api/v1/onboarding/interests/skip`.
5. Account settings can read and replace choices through `GET|PUT /api/v1/me/interests`.
6. Continue to `GET /api/v1/feed/for-you` after selection or skip.

Updating preferences invalidates existing recommendation feed sessions so the next first page uses
the new choices. `DELETE /api/v1/recommendations/profile` clears inferred behavior but preserves
explicit interests by default. Send `{ "clear_interests": true }` to clear both and require interest
onboarding again.

## Ranking behavior

Selected interests generate eligible candidates through mapped categories and hashtags. Their
explicit-interest ranking boost starts at 20 points for users with at most 20 meaningful events,
drops to 12 through 100 events, and remains a 6-point user-controlled signal afterward. Existing
topic affinity, author affinity, quality, freshness, diversity, negative feedback, privacy, safety,
and moderation rules continue to apply.

The administration recommendation page shows completed, skipped, and pending onboarding counts,
plus selection totals for each active or inactive interest. The catalog itself is seeded by
`InterestSeeder`; production catalog changes should be deployed as reviewed data changes.
