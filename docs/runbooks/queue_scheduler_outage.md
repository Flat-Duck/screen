# Queue or Scheduler Outage

## Detect and classify

Use `/operations` snapshot freshness, queue depth by `default`/`security`/`media`, failed jobs,
scheduled-task last success, security-outbox backlog, and media-processing failures. Confirm with
Horizon and infrastructure metrics. Do not assume a stale dashboard means every dependency failed;
it commonly means `schedule:run` itself stopped.

## Queue outage

1. Stop deployments and traffic experiments. Identify whether Redis, worker processes, or one queue
   is affected. Preserve queued payloads; do not flush Redis or mass-retry failed jobs.
2. Restore Redis connectivity/capacity, then restart workers using the deployment supervisor. Start
   `security` first, then `media`, then `default`, unless the incident commander documents another
   priority. Keep concurrency low initially to avoid a database/storage thundering herd.
3. Watch processing rate, retry count, DB connections, storage errors, and duplicate side effects.
   Jobs and security outbox delivery are designed for retries, but verify with samples.
4. Retry failed jobs in bounded batches by queue and failure cause. Never retry malformed or
   permanently unauthorized payloads blindly.

## Scheduler outage

1. Verify the deployment has exactly one effective `* * * * * php artisan schedule:run` invocation.
2. Restore cron/platform scheduler and run `php artisan schedule:list` with production configuration.
3. Do not manually execute every missed task. Run retention and aggregation commands one at a time,
   in chronological/bounded windows, while watching locks and load.
4. Confirm `operations:capture-health` and all critical workflow rows record new successful times.

Communicate impact on screenshot processing, security email, deletion SLAs, analytics freshness, and
notifications. Close only after backlog returns to normal, failures are classified, and follow-up
alerts/capacity work has owners.
