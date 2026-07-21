<?php

namespace Tests\Feature;

use App\Http\Requests\EnrollDeviceRequest;
use App\Http\Requests\StoreTelemetryEventsRequest;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_committed_openapi_document_matches_every_registered_mobile_route(): void
    {
        $this->artisan('api:export-contract --check')->assertSuccessful();
        $document = $this->contract();
        $contractOperations = [];
        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $contractOperations[] = strtoupper($method).' '.$path;
                if (isset($operation['security'])) {
                    $this->assertContains($operation['x-auth-principal'], ['user', 'device'], "Authenticated operation {$method} {$path} lacks a principal");
                } else {
                    $this->assertSame('public', $operation['x-auth-principal']);
                }
            }
        }
        $routes = [];
        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $method.' /'.$route->uri();
            }
        }
        sort($routes);
        sort($contractOperations);
        $this->assertSame($routes, $contractOperations);
    }

    public function test_openapi_references_and_operation_ids_are_valid_and_unique(): void
    {
        $document = $this->contract();
        $ids = [];
        $walk = function (mixed $value) use (&$walk, $document, &$ids): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['operationId'])) {
                $ids[] = $value['operationId'];
            }
            if (isset($value['$ref'])) {
                $target = $document;
                foreach (explode('/', substr($value['$ref'], 2)) as $segment) {
                    $this->assertArrayHasKey($segment, $target, "Broken OpenAPI reference {$value['$ref']}");
                    $target = $target[$segment];
                }
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($document);
        $this->assertSameSize(array_unique($ids), $ids, 'OpenAPI operationId values must be unique.');
    }

    public function test_mobile_request_models_track_required_backend_validation_fields(): void
    {
        $schemas = $this->contract()['components']['schemas'];
        $this->assertSame($this->requiredTopLevel((new EnrollDeviceRequest)->rules()), $schemas['DeviceEnrollmentRequest']['required']);
        $this->assertSame($this->requiredTopLevel((new StoreTelemetryEventsRequest)->rules()), $schemas['TelemetryBatchRequest']['required']);
        $this->assertSame(
            ['event_id', 'kind', 'name', 'occurred_at'],
            $schemas['TelemetryEventPayload']['required'],
        );
    }

    public function test_documented_post_and_user_models_accept_real_resource_payloads(): void
    {
        $viewer = User::factory()->create();
        $post = Post::factory()->for($viewer)->create();
        PostMedia::factory()->for($post)->create();
        $post->load(['user', 'media', 'category'])->loadCount(['likes', 'comments']);
        $post->is_liked = false;
        $post->is_saved = false;
        $user = $viewer->loadCount(['posts', 'followers', 'following']);
        $request = Request::create('/api/v1/posts/'.$post->id);
        $request->setUserResolver(fn () => $viewer);
        $schemas = $this->contract()['components']['schemas'];

        $postPayload = (new PostResource($post))->response($request)->getData(true)['data'];
        $userPayload = (new UserResource($user))->response($request)->getData(true)['data'];
        $this->assertSame([], array_values(array_diff($schemas['Post']['required'], array_keys($postPayload))));
        $userRequired = $schemas['User']['allOf'][1]['required'];
        $summaryRequired = $schemas['UserSummary']['required'];
        $this->assertSame([], array_values(array_diff([...$summaryRequired, ...$userRequired], array_keys($userPayload))));
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return json_decode(file_get_contents(base_path('docs/openapi-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $rules @return list<string> */
    private function requiredTopLevel(array $rules): array
    {
        return collect($rules)->filter(fn (mixed $rule, string $field): bool => ! str_contains($field, '.') && is_array($rule) && in_array('required', $rule, true))->keys()->values()->all();
    }
}
