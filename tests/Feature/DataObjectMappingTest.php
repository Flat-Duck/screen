<?php

namespace Tests\Feature;

use App\Data\Posts\CreatePostData;
use App\Data\Telemetry\RegisterDeviceData;
use App\Data\Telemetry\TelemetryBatchData;
use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\StoreTelemetryEventsRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Tests\TestCase;

class DataObjectMappingTest extends TestCase
{
    public function test_register_device_request_builds_typed_data(): void
    {
        $request = RegisterDeviceRequest::create('/', 'POST', [
            'device_id' => '7b32fe2b-f9a7-48ad-86e7-799e18be9250',
            'manufacturer' => 'Google',
            'sdk_int' => 35,
        ]);
        $this->validateRequest($request);

        $data = $request->toData();

        $this->assertInstanceOf(RegisterDeviceData::class, $data);
        $this->assertSame('Google', $data->manufacturer);
        $this->assertSame(35, $data->sdkInt);
        $this->assertNull($data->model);
    }

    public function test_telemetry_request_builds_typed_batch_and_events(): void
    {
        $request = StoreTelemetryEventsRequest::create('/', 'POST', [
            'device' => [
                'device_id' => '7b32fe2b-f9a7-48ad-86e7-799e18be9250',
                'app_version_name' => '2.0',
                'app_version_code' => 20,
            ],
            'events' => [[
                'event_id' => '1bcde1b8-33c7-4bf8-91c1-eafdb6af4ca8',
                'kind' => 'fatal_crash',
                'name' => 'fatal_crash',
                'occurred_at' => '2026-07-13T10:00:00+00:00',
                'extras' => ['screen' => 'home'],
                'breadcrumbs' => [['ts' => '1', 'type' => 'navigation', 'name' => 'home']],
                'error' => [
                    'tag' => 'uncaught',
                    'exception_class' => 'IllegalStateException',
                    'stack_trace' => 'trace',
                    'thread_name' => 'main',
                    'is_fatal' => true,
                ],
            ]],
        ]);
        $this->validateRequest($request);

        $data = $request->toData();

        $this->assertInstanceOf(TelemetryBatchData::class, $data);
        $this->assertSame(20, $data->appVersionCode);
        $this->assertCount(1, $data->events);
        $this->assertSame('IllegalStateException', $data->events[0]->exceptionClass);
        $this->assertTrue($data->events[0]->isFatal);
    }

    public function test_store_post_request_builds_typed_data_with_uploaded_files(): void
    {
        $request = StorePostRequest::create('/', 'POST', ['caption' => 'hello']);
        $image = UploadedFile::fake()->image('shot.jpg', 800, 800);
        $request->files->set('images', [$image]);
        $this->validateRequest($request);

        $data = $request->toData();

        $this->assertInstanceOf(CreatePostData::class, $data);
        $this->assertSame('hello', $data->caption);
        $this->assertSame([$image], $data->images);
    }

    private function validateRequest(RegisterDeviceRequest|StoreTelemetryEventsRequest|StorePostRequest $request): void
    {
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->validateResolved();
    }
}
