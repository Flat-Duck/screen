<?php

namespace App\Http\Controllers;

use App\Models\OperationsHealthSnapshot;
use App\Models\ScheduledTaskRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class OperationsDashboardController extends Controller
{
    public function __invoke(): View
    {
        $latest = OperationsHealthSnapshot::query()->latest('captured_at')->first();
        $previous = OperationsHealthSnapshot::query()->where('captured_at', '<=', now()->subDay())->latest('captured_at')->first();
        $apiMetrics = DB::table('api_request_metrics')->where('minute', '>=', now()->subHour())
            ->orderBy('minute')->get()->map(function (object $row): object {
                $row->average_duration_ms = $row->requests > 0 ? round($row->total_duration_ms / $row->requests) : 0;

                return $row;
            });

        return view('operations.index', [
            'snapshot' => $latest,
            'snapshotStale' => ! $latest || $latest->captured_at->lt(now()->subMinutes(5)),
            'storageGrowth' => $latest && $previous
                ? (int) data_get($latest->metrics, 'storage_bytes', 0) - (int) data_get($previous->metrics, 'storage_bytes', 0)
                : null,
            'tasks' => ScheduledTaskRun::query()->orderBy('task_name')->get(),
            'apiMetrics' => $apiMetrics,
        ]);
    }
}
