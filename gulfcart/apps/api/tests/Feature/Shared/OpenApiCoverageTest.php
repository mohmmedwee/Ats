<?php

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Every /api/v1 route must be documented in docs/api/openapi.yaml (AGENTS.md rule 13). */
final class OpenApiCoverageTest extends TestCase
{
    protected bool $seed = false;

    public function test_every_api_route_is_documented(): void
    {
        $spec = (string) file_get_contents(base_path('../../docs/api/openapi.yaml'));
        preg_match_all('#^  (/[^:\s]*):\s*$#m', $spec, $m);
        $documented = array_flip($m[1]);

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/v1/')) {
                continue;
            }
            $path = substr($uri, strlen('/api/v1'));
            if (! isset($documented[$path])) {
                $missing[] = implode('|', $route->methods()).' '.$path;
            }
        }

        $this->assertSame([], $missing, 'Undocumented routes: '.implode(', ', $missing));
    }
}
