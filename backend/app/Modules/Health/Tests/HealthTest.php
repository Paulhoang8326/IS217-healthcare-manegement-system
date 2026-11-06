<?php

namespace App\Modules\Health\Tests;

use Illuminate\Http\Request;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_route_is_loaded_with_api_prefix_and_middleware(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $route = app('router')->getRoutes()->match(Request::create('/api/health'));
        $this->assertContains('api', $route->gatherMiddleware());
        $this->getJson('/api/api/health')->assertNotFound();
    }
}
