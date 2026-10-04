<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * /dev-tool: debug mode ON/OFF toggle.
 * - controller flips APP_DEBUG in .env and clears the config cache
 * - endpoint is super-admin only; page shows the current state
 */
class DevToolDebugToggleTest extends TestCase
{
    public function test_controller_toggles_app_debug_in_env(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/DevToolController.php'));
        $this->assertStringContainsString('function toggleDebug', $ctl);
        $this->assertStringContainsString('function setEnvDebug', $ctl);
        $this->assertStringContainsString('denyUnlessSuperAdmin', $ctl);
        $this->assertStringContainsString('APP_DEBUG=', $ctl);
        $this->assertStringContainsString("base_path('.env')", $ctl);
        $this->assertStringContainsString("'config:clear'", $ctl);
        $this->assertStringContainsString("'appDebug' => (bool) config('app.debug')", $ctl);
    }

    public function test_toggle_debug_route_registered(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('dev-tool/toggle-debug', $routes);
        $this->assertStringContainsString('dev-tool.toggle-debug', $routes);
        $this->assertStringContainsString('toggleDebug', $routes);
    }

    public function test_view_has_debug_toggle_with_state_badge(): void
    {
        $view = file_get_contents(resource_path('views/dev-tool/index.blade.php'));
        $this->assertStringContainsString("route('dev-tool.toggle-debug')", $view);
        $this->assertStringContainsString('Debug mode', $view);
        $this->assertStringContainsString('{{ __(\'ON\') }}', $view);
        $this->assertStringContainsString('{{ __(\'OFF\') }}', $view);
        $this->assertStringNotContainsString('<redacted>', $view);
    }
}
