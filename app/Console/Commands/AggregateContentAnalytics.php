<?php

namespace App\Console\Commands;

use App\Actions\Analytics\AggregateContentAnalyticsDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AggregateContentAnalytics extends Command
{
    protected $signature = 'analytics:aggregate {--date= : One UTC date} {--from= : First UTC date} {--to= : Last UTC date, inclusive}';

    protected $description = 'Rebuild daily content analytics aggregates for a UTC date or range.';

    public function handle(AggregateContentAnalyticsDay $aggregate): int
    {
        try {
            [$from, $to] = $this->range();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $days = $from->diffInDays($to) + 1;
        if ($days > 366) {
            $this->error('A single backfill may not exceed 366 UTC days.');

            return self::INVALID;
        }

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $result = $aggregate($day);
            $suffix = $result['partial'] ? ' (partial)' : '';
            $this->info("Aggregated {$result['date']}{$suffix}: {$result['events']} events, {$result['users']} users, {$result['posts']} posts.");
        }

        return self::SUCCESS;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function range(): array
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($date !== null && ($from !== null || $to !== null)) {
            throw new InvalidArgumentException('Use either --date or --from/--to, not both.');
        }
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('--from and --to must be supplied together.');
        }

        if ($date !== null) {
            $day = CarbonImmutable::parse((string) $date, 'UTC')->startOfDay();

            return [$day, $day];
        }
        if ($from !== null && $to !== null) {
            $first = CarbonImmutable::parse((string) $from, 'UTC')->startOfDay();
            $last = CarbonImmutable::parse((string) $to, 'UTC')->startOfDay();
            if ($last->lt($first)) {
                throw new InvalidArgumentException('--to must not be before --from.');
            }

            return [$first, $last];
        }

        $yesterday = CarbonImmutable::now('UTC')->subDay()->startOfDay();

        return [$yesterday, $yesterday];
    }
}
