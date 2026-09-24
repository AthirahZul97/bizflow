<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_page_reports_healthy_when_database_is_reachable(): void
    {
        $response = $this->get(route('health'));

        $response->assertOk();
        $response->assertSee('Healthy');
        $response->assertSee('Connected');
    }

    public function test_health_page_reports_degraded_when_database_is_unreachable(): void
    {
        config(['database.connections.sqlite.database' => storage_path('framework/testing/missing.sqlite')]);
        DB::purge('sqlite');

        $response = $this->get(route('health'));

        $response->assertStatus(503);
        $response->assertSee('Degraded');
        $response->assertSee('Unavailable');
    }
}
