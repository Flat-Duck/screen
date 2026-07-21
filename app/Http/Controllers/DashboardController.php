<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\TelemetryEvent;
use App\Services\DashboardMetricsService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics): View
    {
        return view('dashboard', [
            'totalDevices' => Device::count(),
            'eventsToday' => TelemetryEvent::where('kind', TelemetryEvent::KIND_EVENT)
                ->whereDate('received_at', today())->count(),
            'crashesToday' => TelemetryEvent::crashes()
                ->whereDate('received_at', today())->count(),
            'recentCrashes' => TelemetryEvent::crashes()
                ->with(['device', 'user', 'deviceSession'])
                ->latest('received_at')
                ->limit(5)
                ->get(),
            ...$metrics->summary(),
        ]);
    }
}
