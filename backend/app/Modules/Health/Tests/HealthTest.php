<?php

namespace App\Modules\Health\Tests;

use App\Shared\Http\Middleware\JwtFilter;
use Illuminate\Http\Request;
use Tests\TestCase;

class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(JwtFilter::class);
    }

    public function test_health_route_is_loaded_with_api_prefix_and_middleware(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $route = app('router')->getRoutes()->match(Request::create('/api/health'));
        $this->assertContains('api', $route->gatherMiddleware());
        $this->getJson('/api/api/health')->assertNotFound();
    }
}
