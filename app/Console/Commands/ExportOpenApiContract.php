<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class ExportOpenApiContract extends Command
{
    protected $signature = 'api:export-contract {--check : Fail when the committed contract differs}';

    protected $description = 'Export and validate the OpenAPI contract for the native mobile API';

    public function handle(): int
    {
        $json = json_encode($this->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $path = base_path('docs/openapi-v1.json');

        if ($this->option('check')) {
            if (! File::exists($path) || File::get($path) !== $json) {
                $this->components->error('docs/openapi-v1.json is stale. Run php artisan api:export-contract.');

                return self::FAILURE;
            }
            $this->components->info('OpenAPI contract is current.');

            return self::SUCCESS;
        }

        File::put($path, $json);
        $this->components->info('Exported docs/openapi-v1.json.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        $paths = [];
        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.$route->uri();
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, strtolower($method));
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Screenshut Mobile API', 'version' => '1.0.0', 'description' => 'Native mobile API. The web surface is reserved for administrators and moderation.'],
            'servers' => [['url' => '{baseUrl}', 'variables' => ['baseUrl' => ['default' => 'https://api.example.com']]]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum token']],
                'responses' => [
                    'JsonSuccess' => ['description' => 'Successful JSON response', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/JsonObject']]]],
                    'Unauthenticated' => ['description' => 'Missing, expired, or wrong-principal token', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'Forbidden' => ['description' => 'Authenticated principal lacks permission', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    'ValidationError' => ['description' => 'Request validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]],
                    'RateLimited' => ['description' => 'Rate limit exceeded', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                ],
                'schemas' => $this->schemas(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method): array
    {
        $middleware = $route->gatherMiddleware();
        $action = $route->getActionName();
        $operation = [
            'operationId' => Str::of($action)->afterLast('\\')->replace('@', '_')->camel()->toString(),
            'tags' => [Str::headline(explode('/', Str::after($route->uri(), 'api/v1/'))[0])],
            'responses' => [
                '200' => ['$ref' => '#/components/responses/JsonSuccess'],
                '204' => ['description' => 'Successful operation with no response body'],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
                '429' => ['$ref' => '#/components/responses/RateLimited'],
            ],
            'x-auth-principal' => $this->principal($middleware),
        ];
        if ($responseSchema = $this->responseSchema($method, Str::after($route->uri(), 'api/v1/'))) {
            $operation['responses']['200'] = ['description' => 'Successful response', 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$responseSchema}"]]]];
            $operation['responses']['201'] = $operation['responses']['200'];
        }
        if (in_array('auth:sanctum', $middleware, true)) {
            $operation['security'] = [['bearerAuth' => []]];
        }
        $parameters = $route->parameterNames();
        if ($parameters !== []) {
            $operation['parameters'] = array_map(fn (string $name): array => [
                'name' => $name, 'in' => 'path', 'required' => true,
                'schema' => ['type' => str_ends_with(strtolower($name), 'id') || in_array($name, ['post', 'user', 'comment', 'collection', 'conversation', 'notification', 'hashtag'], true) ? 'integer' : 'string'],
            ], $parameters);
        }
        if (in_array($method, ['post', 'put', 'patch', 'delete'], true) && ($schema = $this->requestSchema($method, Str::after($route->uri(), 'api/v1/')))) {
            $contentType = $schema === 'CreatePostRequest' ? 'multipart/form-data' : 'application/json';
            $operation['requestBody'] = ['required' => true, 'content' => [$contentType => ['schema' => ['$ref' => "#/components/schemas/{$schema}"]]]];
        }

        return $operation;
    }

    /** @param array<int|string, mixed> $middleware */
    private function principal(array $middleware): string
    {
        if (collect($middleware)->contains(fn (mixed $value): bool => is_string($value) && (str_contains($value, 'EnsureSanctumPrincipalIsDevice') || str_starts_with($value, 'auth.device')))) {
            return 'device';
        }
        if (collect($middleware)->contains(fn (mixed $value): bool => is_string($value) && (str_contains($value, 'EnsureSanctumPrincipalIsUser') || $value === 'auth.user'))) {
            return 'user';
        }

        return 'public';
    }

    private function requestSchema(string $method, string $uri): ?string
    {
        return match (true) {
            $method === 'post' && $uri === 'devices/enroll' => 'DeviceEnrollmentRequest',
            $method === 'post' && $uri === 'telemetry/events' => 'TelemetryBatchRequest',
            $method === 'post' && $uri === 'analytics/content-events' => 'ContentEventBatchRequest',
            $method === 'post' && $uri === 'posts' => 'CreatePostRequest',
            $method === 'post' && str_ends_with($uri, '/messages') => 'CreateMessageRequest',
            default => in_array($method, ['post', 'put', 'patch'], true) ? 'JsonObject' : null,
        };
    }

    private function responseSchema(string $method, string $uri): ?string
    {
        if (! in_array($method, ['get', 'post'], true)) {
            return null;
        }

        return match (true) {
            $uri === 'posts/{post}', $uri === 'posts', str_ends_with($uri, '/publish') => 'PostEnvelope',
            in_array($uri, ['feed', 'feed/following', 'feed/for-you', 'explore', 'search/posts', 'saved-posts', 'archived-posts', 'recently-deleted-posts'], true),
            in_array($uri, ['users/{user}/posts', 'hashtags/{hashtag}/posts'], true) => 'PostPage',
            $uri === 'users/{user}' => 'UserEnvelope',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function schemas(): array
    {
        return [
            'JsonObject' => ['type' => 'object', 'additionalProperties' => true],
            'Error' => ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]],
            'ValidationError' => ['allOf' => [
                ['$ref' => '#/components/schemas/Error'],
                ['type' => 'object', 'required' => ['errors'], 'properties' => [
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ]],
            ]],
            'DeviceEnrollmentRequest' => ['type' => 'object', 'required' => ['device_uuid', 'os_name'], 'properties' => [
                'device_uuid' => ['type' => 'string', 'format' => 'uuid'],
                'manufacturer' => ['type' => 'string', 'maxLength' => 255],
                'brand' => ['type' => 'string', 'maxLength' => 255],
                'model' => ['type' => 'string', 'maxLength' => 255],
                'os_name' => ['type' => 'string'], 'os_version' => ['type' => 'string'],
                'sdk_int' => ['type' => 'integer'],
                'app_version_name' => ['type' => ['string', 'null']],
                'app_version_code' => ['type' => ['integer', 'null']],
            ]],
            'TelemetryBatchRequest' => ['type' => 'object', 'required' => ['app', 'events'], 'properties' => [
                'app' => ['$ref' => '#/components/schemas/AppBuild'],
                'os_version' => ['type' => ['string', 'null']],
                'events' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['$ref' => '#/components/schemas/TelemetryEventPayload']],
            ]],
            'AppBuild' => ['type' => 'object', 'properties' => ['version_name' => ['type' => ['string', 'null']], 'version_code' => ['type' => ['integer', 'null']], 'build_type' => ['type' => ['string', 'null']]]],
            'TelemetryEventPayload' => ['type' => 'object', 'required' => ['event_id', 'kind', 'name', 'occurred_at'], 'properties' => ['event_id' => ['type' => 'string', 'format' => 'uuid'], 'session_id' => ['type' => ['string', 'null'], 'format' => 'uuid'], 'kind' => ['enum' => ['event', 'error', 'fatal_crash']], 'name' => ['type' => 'string'], 'occurred_at' => ['type' => 'string', 'format' => 'date-time'], 'extras' => ['type' => 'object'], 'breadcrumbs' => ['type' => 'array'], 'error' => ['$ref' => '#/components/schemas/TelemetryError']]],
            'TelemetryError' => ['type' => ['object', 'null'], 'properties' => ['tag' => ['type' => ['string', 'null']], 'exception_class' => ['type' => ['string', 'null']], 'message' => ['type' => ['string', 'null']], 'stack_trace' => ['type' => ['string', 'null'], 'maxLength' => 4000], 'thread_name' => ['type' => ['string', 'null']], 'is_fatal' => ['type' => ['boolean', 'null']]]],
            'ContentEventBatchRequest' => ['type' => 'object', 'required' => ['events'], 'properties' => [
                'events' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => [
                    'type' => 'object', 'required' => ['event_id', 'event_type', 'author_id', 'surface', 'occurred_at'], 'additionalProperties' => true,
                ]],
            ]],
            'CreatePostRequest' => ['type' => 'object', 'required' => ['images'], 'properties' => ['images' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'string', 'format' => 'binary']], 'caption' => ['type' => ['string', 'null']], 'category_id' => ['type' => ['integer', 'null']], 'content_warning' => ['type' => ['string', 'null'], 'enum' => ['sensitive', 'spoiler', null]]]],
            'CreateMessageRequest' => ['type' => 'object', 'required' => ['body'], 'properties' => ['body' => ['type' => 'string', 'maxLength' => 5000], 'client_message_id' => ['type' => ['string', 'null'], 'format' => 'uuid']]],
            'UserSummary' => ['type' => 'object', 'required' => ['id', 'username', 'name', 'avatar_url'], 'properties' => ['id' => ['type' => 'integer'], 'username' => ['type' => 'string'], 'name' => ['type' => 'string'], 'avatar_url' => ['type' => ['string', 'null']]]],
            'User' => ['allOf' => [['$ref' => '#/components/schemas/UserSummary'], ['type' => 'object', 'required' => ['bio', 'country_code', 'account_visibility', 'posts_count', 'followers_count', 'following_count', 'created_at'], 'properties' => ['bio' => ['type' => ['string', 'null']], 'country_code' => ['type' => ['string', 'null']], 'account_visibility' => ['enum' => ['public', 'private']], 'birth_date' => ['type' => ['string', 'null'], 'format' => 'date'], 'posts_count' => ['type' => 'integer'], 'followers_count' => ['type' => 'integer'], 'following_count' => ['type' => 'integer'], 'is_following' => ['type' => 'boolean'], 'follows_you' => ['type' => 'boolean'], 'follow_request_status' => ['type' => ['string', 'null']], 'is_blocked' => ['type' => 'boolean'], 'is_blocked_by' => ['type' => 'boolean'], 'created_at' => ['type' => 'string', 'format' => 'date-time']]]]],
            'PostMedia' => ['type' => 'object', 'required' => ['id', 'position', 'url', 'original_url', 'width', 'height', 'status', 'alt_text', 'safety_status'], 'properties' => ['id' => ['type' => 'integer'], 'position' => ['type' => 'integer'], 'url' => ['type' => 'string'], 'original_url' => ['type' => 'string'], 'width' => ['type' => ['integer', 'null']], 'height' => ['type' => ['integer', 'null']], 'status' => ['type' => 'string'], 'alt_text' => ['type' => ['string', 'null']], 'safety_status' => ['type' => 'string']]],
            'Post' => ['type' => 'object', 'required' => ['id', 'caption', 'status', 'user', 'media', 'likes_count', 'comments_count', 'comments_enabled', 'reposts_enabled', 'source_application', 'source_url', 'content_warning', 'is_liked', 'is_saved', 'created_at', 'edited_at', 'archived_at', 'deleted_at', 'scheduled_purge_at'], 'properties' => ['id' => ['type' => 'integer'], 'caption' => ['type' => ['string', 'null']], 'status' => ['type' => 'string'], 'user' => ['$ref' => '#/components/schemas/UserSummary'], 'media' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PostMedia']], 'likes_count' => ['type' => 'integer'], 'comments_count' => ['type' => 'integer'], 'comments_enabled' => ['type' => 'boolean'], 'reposts_enabled' => ['type' => 'boolean'], 'category' => ['type' => ['object', 'null']], 'source_application' => ['type' => ['string', 'null']], 'source_url' => ['type' => ['string', 'null'], 'format' => 'uri'], 'content_warning' => ['type' => ['string', 'null']], 'is_liked' => ['type' => 'boolean'], 'is_saved' => ['type' => 'boolean'], 'created_at' => ['type' => 'string', 'format' => 'date-time'], 'edited_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'archived_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'deleted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'scheduled_purge_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'recommendation' => ['type' => 'object']]],
            'PostEnvelope' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/Post']]],
            'UserEnvelope' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/User']]],
            'PostPage' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']], 'links' => ['type' => 'object'], 'meta' => ['type' => 'object']]],
        ];
    }
}
