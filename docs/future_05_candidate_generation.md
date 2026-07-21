# Recommendation Candidate Generation

Milestone 5.1 creates the safe, bounded input set for later recommendation ranking. It does not
change the current mobile feed response and does not attempt to rank or paginate a For You feed.

## Sources and bounds

Every generator implements `RecommendationCandidateSource` and has an independent limit and
freshness window under `social.recommendations`. Sources cover following, followed hashtags,
category affinity, global trending, country trending, two-hop authors, positive author affinity,
similar topics, and new creators. The combined pool is capped at 250 candidates by default.

Each candidate records its post ID, source enum, source-local score, UTC generation timestamp, and
eligibility metadata. Deduplication retains the first source and records other matching sources as
provenance. Source scores are deliberately not compared across generators; normalization and mixing
belong to Milestone 5.2.

## Hard eligibility boundary

`CandidateEligibilityService` is shared by every generator. It excludes the viewer's own posts,
deleted or unavailable accounts, block/mute relationships, recommendation-restricted authors,
posts marked ineligible by moderation, screenshots with warning/failed safety state, and posts the
viewer hid, marked not interested, or reported. Discovery sources require public accounts; the
following source may include a private account the viewer is authorized to follow.

These checks are mandatory policy and are not controlled by experiment flags.

## Hot pools and fallback

Run `php artisan recommendations:refresh-pools` every ten minutes through the scheduler. It writes
global and country sorted sets under the versioned `recommendations:v1:hot` prefix using temporary
keys and atomic rename, then applies a one-hour safety TTL. Changing pool semantics should bump the
prefix version so old keys expire without being served.

If Redis is missing, empty, stale, or unreachable, trending generators use a bounded PostgreSQL
query over the same freshness window. Other generators use the durable follow, hashtag, category,
and daily affinity tables directly.

Deployment therefore requires both the queue worker and the existing once-per-minute Laravel
scheduler invocation.
