<?php

use App\Contracts\ScreenshotTextExtractor;
use App\Data\Screenshots\TextExtractionResult;
use App\Livewire\ScreenshotAnalytics;
use App\Models\Device;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Services\Screenshots\CaptureAnalytics;
use App\Services\Screenshots\CaptureReport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function capturePayload(string $id, string $stage, array $extra = []): array
{
    return ['app' => ['version_name' => 'test'], 'events' => [array_merge([
        'event_id' => (string) Str::uuid(), 'kind' => 'event', 'name' => 'screenshot_capture_v1',
        'occurred_at' => now()->toIso8601String(), 'extras' => ['capture_id' => $id, 'stage' => $stage],
    ], $extra)]];
}

it('deduplicates stages across retries and raw telemetry deletion', function () {
    $this->authenticateDevice();
    $id = (string) Str::uuid();
    $payload = capturePayload($id, 'detected');
    $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();
    $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();
    TelemetryEvent::query()->delete();
    $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected'))->assertOk();
    expect(DB::table('screenshot_captures')->count())->toBe(1);
    expect(DB::table('screenshot_capture_stages')->count())->toBe(1);
});

it('rejects client completions and a capture belonging to another installation', function () {
    $this->authenticateDevice();
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'share_completed'))->assertUnprocessable();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected'))->assertOk();
    $this->authenticateDevice();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'share_tapped'))->assertUnprocessable();
    expect(DB::table('screenshot_capture_stages')->count())->toBe(1);
});

it('never attributes anonymous detection to the currently logged in account', function () {
    $user = User::factory()->create();
    $this->authenticateDevice(Device::factory()->create(['user_id' => $user->id]));
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected'))->assertOk();
    expect(DB::table('screenshot_captures')->where('id', $id)->value('user_id'))->toBeNull();
});

it('records private save completion before queued detection arrives', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $device = Device::factory()->create();
    $issued = $this->startUserSession($user, $device);
    $id = (string) Str::uuid();
    $this->withHeader('Authorization', 'Bearer '.$issued->token)
        ->postJson('/api/v1/private-saves', ['capture_id' => $id, 'image' => UploadedFile::fake()->image('s.png')])
        ->assertCreated();
    expect(DB::table('screenshot_capture_stages')->where('stage', 'private_save_completed')->count())->toBe(1);
    $this->authenticateDevice($device);
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected'))->assertOk();
    expect(DB::table('screenshot_capture_stages')->count())->toBe(2);
});

it('does not count replaced or unavailable overlays as ignored and corrects late action events', function () {
    $this->authenticateDevice();
    foreach (['overlay_replaced', 'overlay_unavailable', 'ignored_timeout'] as $outcome) {
        $id = (string) Str::uuid();
        foreach (['detected', 'overlay_shown', $outcome] as $stage) {
            $this->postJson('/api/v1/telemetry/events', capturePayload($id, $stage))->assertOk();
        }
    }
    $report = app(CaptureReport::class);
    expect((int) $report->aggregates($report->captures())->first()->ignored)->toBe(1);
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'share_tapped'))->assertOk();
    expect((int) $report->aggregates($report->captures())->first()->ignored)->toBe(0);
    expect((int) $report->aggregates($report->captures())->first()->unfinished)->toBe(1);
});

it('keeps the newest library observation and never presents denied access as zero', function () {
    $device = $this->authenticateDevice();
    foreach ([['full', '12', now()], ['partial', '2', now()->subHour()], ['denied', null, now()->addMinute()]] as [$coverage, $count, $at]) {
        $payload = capturePayload((string) Str::uuid(), 'detected', [
            'name' => 'screenshot_library_v1', 'occurred_at' => $at->toIso8601String(),
            'extras' => array_filter(['coverage' => $coverage, 'count' => $count], fn ($v) => $v !== null),
        ]);
        $this->postJson('/api/v1/telemetry/events', $payload)->assertOk();
        $snapshot = DB::table('screenshot_library_snapshots')->where('device_id', $device->id)->first();
        expect($snapshot->screenshot_count)->toBe($coverage === 'denied' ? null : 12);
    }
});

it('protects analytics on page loads and livewire requests and renders empty states', function () {
    $user = User::factory()->create();
    $this->get('/screenshot-analytics')->assertRedirect('/login');
    $this->actingAs($user)->get('/screenshot-analytics')->assertForbidden();
    Livewire::actingAs($user)->test(ScreenshotAnalytics::class)->assertForbidden();
    Gate::define('viewTelemetry', fn () => true);
    $this->actingAs($user)->get('/screenshot-analytics')->assertOk()->assertSee('Screenshot Analytics');
    Livewire::actingAs($user)->test(ScreenshotAnalytics::class)->assertSee('No screenshot activity in this period.');
});

it('attributes delayed detection to the historical validated session', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create();
    $issued = $this->startUserSession($user, $device);
    $at = now();
    $issued->session->update(['ended_at' => $at->copy()->addMinute()]);
    $device->update(['user_id' => null]);
    $this->authenticateDevice($device);
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected', [
        'session_id' => $issued->session->uuid, 'occurred_at' => $at->toIso8601String(),
    ]))->assertOk();
    expect(DB::table('screenshot_captures')->where('id', $id)->value('user_id'))->toBe($user->id);
});

it('does not accept a foreign session for capture attribution', function () {
    $issued = $this->startUserSession(User::factory()->create());
    $this->authenticateDevice();
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected', ['session_id' => $issued->session->uuid]))->assertOk();
    expect(DB::table('screenshot_captures')->where('id', $id)->value('user_id'))->toBeNull();
});

it('rejects cross device private saves before creating any saved file', function () {
    Storage::fake('local');
    $other = Device::factory()->create();
    $id = (string) Str::uuid();
    app(CaptureAnalytics::class)->claim($id, $other->id);
    $issued = $this->startUserSession(User::factory()->create());
    $this->withHeader('Authorization', 'Bearer '.$issued->token)->postJson('/api/v1/private-saves', [
        'capture_id' => $id, 'image' => UploadedFile::fake()->image('s.png'),
    ])->assertUnprocessable()->assertJsonValidationErrors('capture_id');
    expect(DB::table('private_saves')->count())->toBe(0);
    expect(DB::table('screenshot_capture_stages')->count())->toBe(0);
});

it('records published completion only after a successful publish', function () {
    Storage::fake('public');
    $extractor = Mockery::mock(ScreenshotTextExtractor::class);
    $extractor->allows('version')->andReturn('fake-v1');
    $extractor->shouldReceive('extract')->once()->andReturn(new TextExtractionResult('ordinary screen', 'eng'));
    $this->app->instance(ScreenshotTextExtractor::class, $extractor);
    $issued = $this->startUserSession(User::factory()->create());
    $this->withHeader('Authorization', 'Bearer '.$issued->token);
    $analysis = $this->postJson('/api/v1/media/analyses', ['images' => [UploadedFile::fake()->image('s.png', 400, 800)]])->assertAccepted();
    $id = (string) Str::uuid();
    $url = '/api/v1/media/analyses/'.$analysis->json('data.token').'/publish';
    $this->postJson($url, ['capture_id' => $id, 'group_id' => 999999])->assertUnprocessable();
    expect(DB::table('screenshot_capture_stages')->count())->toBe(0);
    $this->postJson($url, ['capture_id' => $id])->assertCreated();
    expect(DB::table('screenshot_capture_stages')->where('stage', 'share_completed')->count())->toBe(1);
});

it('validates library coverage and excludes content metadata', function (array $extras) {
    $this->authenticateDevice();
    $this->postJson('/api/v1/telemetry/events', capturePayload((string) Str::uuid(), 'detected', [
        'name' => 'screenshot_library_v1', 'extras' => $extras,
    ]))->assertUnprocessable();
    expect(DB::table('screenshot_library_snapshots')->count())->toBe(0);
})->with([
    [['coverage' => 'full']],
    [['coverage' => 'denied', 'count' => '0']],
    [['coverage' => 'partial', 'count' => '-1']],
    [['coverage' => 'full', 'count' => '1', 'filename' => 'private.png']],
]);

it('renders cohort filters, lifetime totals, and stale per device library snapshots', function () {
    $admin = User::factory()->create();
    $device = $this->authenticateDevice();
    $id = (string) Str::uuid();
    $old = now()->subDays(40);
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected', ['occurred_at' => $old->toIso8601String()]))->assertOk();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'overlay_shown'))->assertOk();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'ignored_timeout'))->assertOk();
    DB::table('screenshot_library_snapshots')->insert([
        'device_id' => $device->id, 'coverage' => 'partial', 'screenshot_count' => 17, 'observed_at' => now()->subDays(2),
    ]);
    Gate::define('viewTelemetry', fn () => true);
    Livewire::actingAs($admin)->test(ScreenshotAnalytics::class)
        ->assertViewHas('totals', fn ($totals) => (int) $totals->detected === 0)
        ->assertViewHas('lifetime', fn ($totals) => (int) $totals->detected === 1)
        ->assertSee('(stale)')->assertSee('partial')
        ->set('from', $old->toDateString())->set('device', (string) $device->id)->set('user', 'anonymous')
        ->assertViewHas('totals', fn ($totals) => (int) $totals->ignored === 1)
        ->set('device', '999999')->assertViewHas('totals', fn ($totals) => (int) $totals->detected === 0);
});

it('cascades installation deletion without retaining identifiable capture records', function () {
    $device = $this->authenticateDevice();
    $this->postJson('/api/v1/telemetry/events', capturePayload((string) Str::uuid(), 'detected'))->assertOk();
    $device->delete();
    expect(DB::table('screenshot_captures')->count())->toBe(0);
    expect(DB::table('screenshot_capture_stages')->count())->toBe(0);
});

it('reports unfinished per action when the other action completed', function (): void {
    $this->authenticateDevice();
    $id = (string) Str::uuid();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'detected'))->assertOk();
    DB::table('screenshot_capture_stages')->insert([
        'capture_id' => $id, 'stage' => 'private_save_completed', 'occurred_at' => now(),
    ]);
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'private_save_tapped'))->assertOk();
    $this->postJson('/api/v1/telemetry/events', capturePayload($id, 'share_tapped'))->assertOk();
    $report = app(CaptureReport::class);
    $totals = $report->aggregates($report->captures())->first();
    expect((int) $totals->unfinished)->toBe(1);
    expect((int) $totals->private_save_completed)->toBe(1);
    expect((int) $totals->share_tapped)->toBe(1);
});

it('counts each capture once while a retry advances its unfinished state monotonically', function (): void {
    $this->freezeTime();
    $this->authenticateDevice();
    $id = (string) Str::uuid();
    $emit = function (string $stage, int $milliseconds) use ($id): void {
        $this->postJson('/api/v1/telemetry/events', capturePayload($id, $stage, [
            'occurred_at' => now()->addMilliseconds($milliseconds)->toISOString(),
        ]))->assertOk();
    };
    $report = app(CaptureReport::class);
    $totals = fn () => $report->aggregates($report->captures())->first();
    $emit('share_tapped', 0);
    $emit('share_failed', 100);
    expect((int) $totals()->unfinished)->toBe(0);
    $emit('share_started', 200);
    $emit('share_started', 200); // a duplicate stage still counts the capture once
    $emit('share_failed', 150); // late arrival predates the pending retry
    expect((int) $totals()->unfinished)->toBe(1);
    expect((int) $totals()->share_tapped)->toBe(1);
    expect((int) $totals()->share_failed)->toBe(1);
    $emit('share_failed', 300);
    expect((int) $totals()->unfinished)->toBe(0);
});

it('groups stages without multiplying captures or leaking another cohort', function (): void {
    $device = Device::factory()->create();
    $other = Device::factory()->create();
    $current = (string) Str::uuid();
    $outside = (string) Str::uuid();
    $withoutStages = (string) Str::uuid();
    foreach ([[$current, $device->id], [$outside, $other->id], [$withoutStages, $device->id]] as [$id, $deviceId]) {
        DB::table('screenshot_captures')->insert([
            'id' => $id, 'device_id' => $deviceId, 'user_id' => null,
            'created_at' => now(), 'detected_at' => now(),
        ]);
    }
    foreach ([$current, $outside] as $id) {
        foreach (['detected', 'overlay_shown', 'share_tapped', 'share_completed'] as $stage) {
            DB::table('screenshot_capture_stages')->insert([
                'capture_id' => $id, 'stage' => $stage, 'occurred_at' => now(),
            ]);
        }
    }
    $report = app(CaptureReport::class);
    $totals = $report->aggregates($report->captures('anonymous', (string) $device->id))->first();
    expect((int) $totals->detected)->toBe(1)
        ->and((int) $totals->overlay_shown)->toBe(1)
        ->and((int) $totals->share_tapped)->toBe(1)
        ->and((int) $totals->share_converted)->toBe(1)
        ->and((int) $totals->unfinished)->toBe(0);
    $empty = $report->aggregates($report->captures()->where('c.id', (string) Str::uuid()))->first();
    expect((int) $empty->detected)->toBe(0)->and((int) $empty->unfinished)->toBe(0);
});

it('renders the dashboard with a fixed number of analytics queries as rows grow', function (): void {
    $admin = User::factory()->create();
    $device = Device::factory()->create();
    foreach (range(1, 30) as $index) {
        $id = (string) Str::uuid();
        DB::table('screenshot_captures')->insert([
            'id' => $id,
            'device_id' => $device->id,
            'user_id' => null,
            'created_at' => now(),
            'detected_at' => now(),
        ]);
        foreach (['detected', 'overlay_shown', 'share_tapped'] as $stage) {
            DB::table('screenshot_capture_stages')->insert([
                'capture_id' => $id,
                'stage' => $stage,
                'occurred_at' => now(),
            ]);
        }
    }

    Gate::define('viewTelemetry', fn (): bool => true);
    $analyticsQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$analyticsQueries): void {
        if (str_contains($query->sql, 'screenshot_captures') || str_contains($query->sql, 'screenshot_library_snapshots')) {
            $analyticsQueries[] = $query->sql;
        }
    });

    Livewire::actingAs($admin)->test(ScreenshotAnalytics::class)->assertSee('Screenshot Analytics');

    expect(count($analyticsQueries))->toBe(6);
});
