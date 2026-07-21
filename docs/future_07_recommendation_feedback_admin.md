# Recommendation Feedback and Administration

Milestone 5.3 closes the recommendation control loop with user-local feedback, profile reset, and
an audited administrative control plane.

## User feedback semantics

Post feedback is stored per user and post. `not_interested` is reversible; `hidden` is a stronger
v1 state without a mobile restore endpoint. Both are hard candidate exclusions for only the acting
user. Author and hashtag `show_fewer` records are soft signals: author matches receive a -12 score
component and hashtag matches receive -10. These controls do not alter follows, blocks, mutes,
account visibility, or another user's feed.

All mutations delete the acting user's outstanding feed snapshots so stale rankings cannot continue
serving suppressed content. Mobile clients should discard their cursor after successful feedback.

Deleting the recommendation profile removes post/target feedback, author and category affinities,
raw content-interaction events, and recommendation feed sessions. Necessary account, authentication,
device, telemetry, moderation, and social-graph records remain intact.

## Administrative controls

`GET /recommendations` is restricted to roles with `moderation.view` and disables browser caching.
It shows the current Redis hot pool, recent feed-session score components and eligibility metadata,
active/recent global exclusions, and aggregate report/negative-feedback indicators.

Roles with `moderation.manage` can:

- Exclude a post globally with a mandatory reason and optional future expiration.
- Restore an exclusion with a mandatory reason.
- Disable or enable For You serving globally with a mandatory reason.

Every mutation writes an admin audit record. Global exclusions are hard eligibility filters and are
rechecked when a stored page is hydrated. The serving control uses the audited
`recommendations.serving` feature flag, but is evaluated globally rather than as a user rollout.

Disabling recommendation serving produces empty, valid For You responses for new and existing
cursors. The Following feed has no dependency on this flag and remains available.

## Data and retention

`recommendation_post_feedback` and `recommendation_target_feedback` cascade when their user or post
is deleted. `recommendation_exclusions` retains actor, reason, and optional expiration; expired rows
remain visible in the admin history but no longer affect eligibility. Expired feed snapshots continue
to be removed by `recommendations:prune-sessions`.
