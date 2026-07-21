<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class PostmanCollectionTest extends TestCase
{
    public function test_collection_covers_every_mobile_route_with_valid_examples(): void
    {
        $collection = json_decode(file_get_contents(base_path('postman/Screenshot-Social-API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
        $requests = $this->requests($collection['item']);

        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertTrue(
                    collect($requests)->contains(fn (array $request): bool => $request['method'] === $method && $this->routeMatches($request['path'], str_replace('/api/v1/', '/v1/', '/'.$route->uri()))),
                    "Postman is missing {$method} /{$route->uri()}",
                );
            }
        }

        foreach ($requests as $request) {
            $this->assertNotEmpty($request['responses'], "{$request['name']} must include a saved response example.");
            if ($request['raw_body'] !== null) {
                json_decode($request['raw_body'], true, flags: JSON_THROW_ON_ERROR);
            }
            foreach ($request['responses'] as $response) {
                if (($response['body'] ?? '') !== '') {
                    json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{name: string, method: string, path: string, raw_body: string|null, responses: list<array<string, mixed>>}>
     */
    private function requests(array $items): array
    {
        $requests = [];
        foreach ($items as $item) {
            if (isset($item['request'])) {
                $url = $item['request']['url'];
                $url = is_array($url) ? $url['raw'] : $url;
                $path = preg_replace('/\?.*/', '', str_replace('{{base_url}}', '', $url));
                $requests[] = [
                    'name' => $item['name'],
                    'method' => $item['request']['method'],
                    'path' => $path,
                    'raw_body' => ($item['request']['body']['mode'] ?? null) === 'raw' ? $item['request']['body']['raw'] : null,
                    'responses' => $item['response'] ?? [],
                ];
            }
            if (isset($item['item'])) {
                $requests = [...$requests, ...$this->requests($item['item'])];
            }
        }

        return $requests;
    }

    private function routeMatches(string $postmanPath, string $routePath): bool
    {
        $pattern = preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($routePath, '#'));

        return preg_match('#^'.$pattern.'$#', $postmanPath) === 1;
    }
}
