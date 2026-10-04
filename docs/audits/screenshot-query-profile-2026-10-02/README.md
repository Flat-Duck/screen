# Screenshot report query profile — 2026-10-02

PostgreSQL 17.9, isolated local Unix-socket cluster, 100,000 synthetic captures across
365 days and 1,000 devices, 400,841 stage rows. No application, staging, or production data
was used. The schema/indexes reproduce the capture/stage migrations; foreign keys to
users/devices were omitted because these aggregates do not join those tables.

`seed.sql` defines the reproducible dataset. Baseline SQL was generated from CaptureReport
before this optimization. Optimized SQL was generated from the revised service. The period
queries cover September 3 through October 2, inclusive. `comparison-plans.json` contains
EXPLAIN ANALYZE/BUFFERS output in lifetime baseline, lifetime optimized, period baseline,
period optimized order. These are performance measurements, not correctness test results.

| Warm-cache query | Baseline | Grouped stages | Shared buffer hits, before → after |
| --- | ---: | ---: | ---: |
| Lifetime | 2,349.057 ms | 428.950 ms | 6,890,451 → 8,347 |
| 30 days | 188.627 ms | 60.007 ms | 567,840 → 8,347 |

The initial lifetime measurement was 2,574.561 ms versus 441.542 ms after rewriting.
The baseline contains dozens of correlated subplans, many looping 100,000 times.
The replacement groups matching stage rows once and joins the result onto the capture
cohort. Capture-level presence counts, latest per-action timestamps, nullable stage data,
and late-event visibility remain the intended semantics. No aggregate cache or rollup
introduces stale totals. The canonical cohort-date expression remains unchanged.

This change reduces aggregate work, not dashboard query count. Source inspection shows
seven dashboard queries with both paginations (three totals/trend queries and two queries
per paginated table); actual rendered query-count instrumentation remains pending.
Full correctness suites, PostgreSQL integration coverage, production-shaped distributions,
and deployment measurements remain required. These timings do not establish a production SLA.

To reproduce, load seed.sql into an empty disposable database, then run each SQL file
using psql with ON_ERROR_STOP. Never load the seed into an application database.
