<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * /dev-tool: "Clear everything" cache button.
 * - controller clears compiled views, application cache, config, route,
 *   event, compiled services and the Spatie permission cache in one action
 * - endpoint is super-admin only; no data is touched
 */
class DevToolClearAllCachesTest extends TestCase
{
    public function test_controller_clears_every_cache(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/DevToolController.php'));
        $this->assertStringContainsString('function clearAllCaches', $ctl);
        $this->assertStringContainsString('denyUnlessSuperAdmin', $ctl);
        foreach (['view:clear', 'cache:clear', 'config:clear', 'route:clear', 'event:clear', 'clear-compiled', 'permission:cache-reset'] as $command) {
            $this->assertStringContainsString("'".$command."'", $ctl);
        }
        $this->assertStringContainsString('opcache_reset', $ctl);
    }

    public function test_clear_all_caches_route_registered(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('dev-tool/clear-all-caches', $routes);
        $this->assertStringContainsString('dev-tool.clear-all-caches', $routes);
        $this->assertStringContainsString('clearAllCaches', $routes);
    }

    public function test_view_has_clear_everything_button(): void
    {
        $view = file_get_contents(resource_path('views/dev-tool/index.blade.php'));
        $this->assertStringContainsString("route('dev-tool.clear-all-caches')", $view);
        $this->assertStringContainsString('Clear everything', $view);
        $this->assertStringNotContainsString('<redacted>', $view);
    }
}
