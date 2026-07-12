# The Recommendation "Algorithm" — Trending & Feed Discovery

## Context

We looked at Twitter's open-source [`the-algorithm`](https://github.com/twitter/the-algorithm)
as a reference for what a real recommendation pipeline looks like, then deliberately
built a much smaller version of the same *idea* — not the same system. Twitter's
architecture (candidate sourcing across Earlybird/Tweet-Mixer/GraphJet, a Light-Ranker
plus a neural Heavy-Ranker, SimClusters/TwHIN embeddings, Kafka-streamed real-time
signals) is built for a billion-post corpus and a large ML team. None of the reasons
that complexity exists apply here: this app runs on a single ~8GB box with Postgres and
Redis, and "the feed feels a little more alive than pure chronological" is the entire
goal — not marginal engagement-percentage gains at planet scale.

So this is a **no-ML, formula-based** ranking system: a scheduled job scores recent
posts with a cheap, explainable formula (the same style Reddit and Hacker News use),
publishes the ranking to Redis, and the feed blends a couple of top-scoring posts from
outside your network into your normal chronological feed. That's the whole system.

## What's in it

Three pieces, matching (at toy scale) the three stages a real system has —
candidate sourcing, ranking, and mixing:

| Piece | File | Role |
|---|---|---|
| Ranking job | `app/Console/Commands/RefreshTrendingPosts.php` | Scores recent posts, writes them to Redis |
| Candidate sourcing + mixing | `app/Services/FeedService.php` | Reads Redis, filters, blends into the feed |
| Config | `config/social.php` (`trending` key) | All weights/knobs, env-overridable |

Scheduled in `routes/console.php`:
```php
Schedule::command('posts:refresh-trending')->everyTenMinutes();
```
(requires the app's `* * * * * php artisan schedule:run` cron entry, same as
`posts:prune-deleted`).

## How the ranking job works

`posts:refresh-trending` runs every 10 minutes and:

1. Pulls every post created within a rolling window (`trending.window_days`, default
   **7 days**) — bounding the working set is what keeps this cheap regardless of how
   large the `posts` table grows over time.
2. For each post, computes:
   ```
   engagement = likes_count * like_weight + comments_count * comment_weight
   score      = engagement / (age_hours + 2) ^ gravity
   ```
   This is Hacker News's own ranking formula (`gravity` defaults to **1.8**, HN's own
   constant). The `+ 2` avoids division blowups for brand-new posts; the power-law decay
   means a post's score drops off *fast* as it ages, regardless of how much engagement
   it racked up — an old viral post doesn't camp at the top forever.
3. Writes `[score, post_id]` pairs into a Redis **sorted set** (`ZADD`) under a temporary
   key, processing posts in chunks of 500 (`chunkById`) to keep memory flat no matter how
   many posts are in the window.
4. Atomically renames the temp key over the real one (`trending:posts` by default) — so
   a reader can never see a half-populated set while the job is mid-run.
5. Sets a safety TTL on the published key (`trending.safety_ttl_minutes`, default
   **60 minutes**). If the cron job ever stops running or Redis has an outage, the key
   simply expires instead of serving an increasingly stale ranking forever.

If the whole run fails (Redis down, etc.), the command catches the exception, reports
it, and exits non-zero — it never leaves a partial/corrupt set behind, and it never
takes down the rest of the scheduler.

## How the feed uses it

`GET /v1/feed` (`FeedController` → `FeedService`) works exactly as before by default:
posts from people you follow, reverse-chronological, cursor-paginated. The only change
is on a **fresh feed load with no cursor** — i.e. the first page:

1. `FeedService::discoveryCandidates()` reads the top ~50 post IDs off the Redis sorted
   set (`ZREVRANGE`), then filters out anything by the viewer themselves or an author
   they already follow (that content already appears via the normal in-network query —
   discovery exists to surface people you *don't* follow yet).
2. `FeedService::injectDiscovery()` splices up to two of those posts into fixed slot
   positions in the page (`trending.discovery_positions`, default **[3, 8]** — i.e. the
   4th and 9th posts in your feed), clamped to however many posts are actually on the
   page.
3. Everything (in-network + injected discovery posts) gets `is_liked` annotated together
   in one query before being serialized — from the API response's shape, a discovery
   post looks exactly like any other post in `PostResource`. There is no new field
   telling the client "this one's a recommendation" — nothing to build on the Android
   side for this feature.

**Only the first page does this.** A `cursor` query param means "continue from this
exact position in the in-network sequence" — splicing a second, independently-ranked
source into a later page would make that cursor's position meaningless. This is a
deliberate scope cut, not an oversight: correctly interleaving two paginated sources is
real distributed-systems complexity (this is a large part of what Twitter's Home-Mixer
actually does), and wasn't worth it for what's fundamentally a "first impression" feature.

## Fails open, always

Every Redis call in `FeedService::discoveryCandidates()` is wrapped in a try/catch. If
Redis is unreachable, times out, or the key doesn't exist yet (fresh install, job hasn't
run), the method just returns no discovery candidates — the core chronological feed is
completely unaffected. This app's telemetry/error tracking still gets a report of the
failure so it's visible operationally, but a user never sees a broken feed because of
this feature. This was a deliberate design constraint from the start, not a side effect:
recommendation is a nice-to-have layered on top of a feed that has to work regardless.

## Tuning

Everything is in `config('social.trending')`, overridable via `.env`:

| Key | Env var | Default | Effect |
|---|---|---|---|
| `window_days` | `SOCIAL_TRENDING_WINDOW_DAYS` | 7 | How far back a post is eligible to trend |
| `like_weight` | `SOCIAL_TRENDING_LIKE_WEIGHT` | 3 | Weight of a like in the engagement score |
| `comment_weight` | `SOCIAL_TRENDING_COMMENT_WEIGHT` | 5 | Weight of a comment (higher — commenting is a stronger signal than liking) |
| `gravity` | `SOCIAL_TRENDING_GRAVITY` | 1.8 | Higher = faster decay with age |
| `redis_key` | `SOCIAL_TRENDING_REDIS_KEY` | `trending:posts` | Where the sorted set lives |
| `safety_ttl_minutes` | `SOCIAL_TRENDING_SAFETY_TTL_MINUTES` | 60 | How long a published ranking survives without a fresh job run |
| `discovery_positions` | — (code only) | `[3, 8]` | Slot positions in the first feed page where discovery posts get inserted |

Changing `like_weight`/`comment_weight`/`gravity` only affects the *next* scheduled run
— there's no need to re-migrate or backfill anything, the whole ranking is disposable
and rebuilt from scratch every 10 minutes.

## What was deliberately left out

Everything below is a real component of Twitter's actual system, and none of it was
built here — on purpose, as overkill for this app's scale:

- **A learned ranking model** (Twitter's Heavy-Ranker is a real neural net with ~48M
  parameters). The formula above is the entire "model."
- **Embeddings / graph-based candidate generation** (SimClusters, TwHIN, GraphJet) — the
  discovery pool here is just "recent + engaged," not similarity- or graph-based.
- **Real-time streaming** (Kafka/Unified-User-Actions) — scores update every 10 minutes
  on a schedule, not on every like/comment as it happens.
- **Personalization/affinity** (ranking differently per viewer based on their own
  engagement history) — the trending set is the same for every viewer; only the
  *filtering* (exclude people you follow/yourself) is personalized. A per-viewer
  affinity boost was discussed as a possible future step but isn't built — it would
  still just be another cheap formula, not ML, if it's ever worth adding.
- **Denormalized `likes_count`/`comments_count` columns** — the ranking job uses
  `withCount()` over the bounded recent-posts window, which was judged fast enough at
  this scale. Worth revisiting only if that query is ever measured as a real bottleneck.

## Operational notes

- **Requires Redis** to do anything (`REDIS_HOST`/`PORT` in `.env`, `phpredis` PHP
  extension). Without it, the feature silently no-ops per the fail-open design above.
- **Requires the cron entry** for `php artisan schedule:run` to actually fire the
  10-minute job — same requirement the pre-existing `posts:prune-deleted` job already
  has.
- No database migration is specific to this feature — it's Redis-only, no new columns
  or tables.
