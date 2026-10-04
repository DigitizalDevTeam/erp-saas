<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * /dev-tool: seeder select dropdown + Run.
 * - controller discovers seeder classes from database/seeders
 * - run endpoint is super-admin only, validates against the discovered list,
 *   and runs db:seed --class ... --force
 */
class DevToolSeederDropdownTest extends TestCase
{
    public function test_controller_discovers_and_runs_seeders(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/DevToolController.php'));
        $this->assertStringContainsString('function seederClasses', $ctl);
        $this->assertStringContainsString("database_path('seeders')", $ctl);
        $this->assertStringContainsString('extends\\s+Seeder', $ctl);

        $this->assertStringContainsString('function runSeeder', $ctl);
        $this->assertStringContainsString('denyUnlessSuperAdmin', $ctl);
        $this->assertStringContainsString("'db:seed'", $ctl);
        $this->assertStringContainsString("'--force' => true", $ctl);
        $this->assertStringContainsString("'seeders' => \$this->seederClasses()", $ctl);
    }

    public function test_run_seeder_route_registered(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString("dev-tool/run-seeder", $routes);
        $this->assertStringContainsString('dev-tool.run-seeder', $routes);
        $this->assertStringContainsString('runSeeder', $routes);
    }

    public function test_view_has_seeder_dropdown_and_result_card(): void
    {
        $view = file_get_contents(resource_path('views/dev-tool/index.blade.php'));
        $this->assertStringContainsString('name="seeder"', $view);
        $this->assertStringContainsString('{{ __(\'Select seeder\') }}', $view);
        $this->assertStringContainsString('{{ __(\'Run seeder\') }}', $view);
        $this->assertStringContainsString("route('dev-tool.run-seeder')", $view);
        $this->assertStringContainsString("session('seed_report')", $view);
    }
}
