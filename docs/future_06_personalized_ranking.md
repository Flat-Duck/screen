# Personalized Ranking and Feed Sessions

Milestone 5.2 turns the bounded candidates from Milestone 5.1 into an explainable For You feed while
keeping the Following feed explicitly chronological.

## API contract

`GET /api/v1/feed/following` uses the existing database cursor and returns followed accounts only.
`GET /api/v1/feed/for-you?per_page=15` creates a recommendation session and returns an opaque
`meta.next_cursor`. Pass that cursor unchanged for subsequent pages. Calling the endpoint without a
cursor always creates a new ranking request.

The For You response includes a feed-session UUID and request UUID in `meta`. Every returned post
includes the same request UUID plus its primary candidate source and a non-sensitive display reason.
Clients should attach these values to content analytics events so exposure and engagement can be
attributed to the ranking request.

Cursors are encrypted, bound to the authenticated user, and valid only while the persisted session
is active. Sessions expire after 30 minutes by default. Invalid, expired, or cross-account cursors
return `422` on `cursor`; clients should restart without a cursor.

## Explainable score

The deterministic v1 score combines source-local score, recent author and category affinity,
freshness, unique-viewer engagement quality, save/share/repost signals, social proof, a new-creator
boost, already-seen penalty, and negative-feedback/manipulation penalty. Component scores are stored
inside the short-lived server snapshot for diagnostics but are not exposed to mobile clients.

No machine-learning model or opaque embedding score is used. `SOCIAL_RECOMMENDATION_RANKING_VERSION`
identifies the formula stored with each session.

## Mixing and safety

The ranker processes highest scores first while limiting repeated authors, categories, and candidate
sources within each page-sized window. A limit is relaxed only if a sparse pool has no alternative;
this avoids turning a healthy diverse pool into a repetitive feed without making small accounts see
an artificially empty feed.

Candidate eligibility is checked during generation and again when each page is hydrated. The second
check deliberately permits an existing snapshot to omit a post after a block, mute, privacy,
moderation, recommendation-restriction, screenshot-safety, or negative-feedback change. Policy wins
over snapshot stability.

## Persistence and operations

`recommendation_feed_sessions` stores at most the configured bounded candidate count, ordered score
components, and expiry. The hourly `recommendations:prune-sessions` task removes expired snapshots;
deployments must keep Laravel's scheduler running once per minute. Creating a new session also
cleans expired snapshots belonging to that user.

Redis is optional at request time: global and regional candidate generators fall back to bounded
PostgreSQL queries when hot pools are empty, stale, or unreachable. The Following endpoint does not
depend on candidate pools or recommendation session storage beyond the primary database.
