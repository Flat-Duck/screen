<?php

namespace App\Livewire;

use App\Services\Screenshots\CaptureReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ScreenshotAnalytics extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $device = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->subDays(29)->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->resetPage('libraries');
    }

    public function render(): View
    {
        Gate::authorize('viewTelemetry');
        $this->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user' => ['nullable', 'regex:/^(anonymous|[0-9]+)$/'],
            'device' => ['nullable', 'regex:/^[0-9]+$/'],
        ]);
        $report = app(CaptureReport::class);
        $base = $report->captures($this->user, $this->device);
        $period = (clone $base)->whereBetween(DB::raw('COALESCE(c.detected_at, c.created_at)'), [
            CarbonImmutable::parse($this->from)->startOfDay(), CarbonImmutable::parse($this->to)->endOfDay(),
        ]);
        $totals = $report->aggregates(clone $period)->first();
        $lifetime = $report->aggregates(clone $base)->first();
        $daily = $report->aggregates(clone $period)
            ->selectRaw('DATE(COALESCE(c.detected_at, c.created_at)) as day')->groupBy('day')->orderBy('day')->get();
        $breakdown = $report->aggregates(clone $period)
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('devices as d', 'd.id', '=', 'c.device_id')
            ->addSelect(['c.user_id', 'c.device_id', 'u.username', 'd.model'])
            ->groupBy('c.user_id', 'c.device_id', 'u.username', 'd.model')
            ->orderBy('c.device_id')->orderBy('c.user_id')->paginate(20);
        $libraries = DB::table('screenshot_library_snapshots as l')
            ->join('devices as d', 'd.id', '=', 'l.device_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->when($this->user === 'anonymous', fn ($q) => $q->whereNull('l.user_id'))
            ->when(ctype_digit($this->user), fn ($q) => $q->where('l.user_id', (int) $this->user))
            ->when(ctype_digit($this->device), fn ($q) => $q->where('l.device_id', (int) $this->device))
            ->select(['l.*', 'd.model', 'u.username'])->orderByDesc('l.observed_at')->paginate(20, pageName: 'libraries');

        return view('livewire.screenshot-analytics', compact('totals', 'lifetime', 'daily', 'breakdown', 'libraries'));
    }
}
