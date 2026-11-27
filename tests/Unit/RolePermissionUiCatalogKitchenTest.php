<?php

namespace Tests\Unit;

use App\Services\RolePermissionUiCatalog;
use Tests\TestCase;

/**
 * Coverage guard for the Roles edit screen: every permission a controller
 * checks must be assignable on the form, or a role can only ever get the
 * menu checkbox and every page in the module denies access (hit 2026-11-27:
 * role 223 "Stock Control" held all 11 menu.kitchen_production.* permissions
 * but zero kitchen action permissions, so /kitchen-recipes denied access
 * even though the menu showed).
 */
class RolePermissionUiCatalogKitchenTest extends TestCase
{
    /** The kitchen action permissions the kitchen controllers check. */
    private const KITCHEN_ACTION_PERMISSIONS = [
        'manage kitchen production',
        'create kitchen recipes',
        'approve kitchen recipes',
        'manage kitchen production orders',
        'approve kitchen production orders',
        'approve kitchen production plans',
        'manage kitchen requisitions',
        'approve kitchen requisitions',
        'manage kitchen losses',
        'approve kitchen losses',
        'view kitchen reports',
        'manage kitchen buffets',
        'manage kitchen daily specials',
    ];

    public function test_kitchen_action_permissions_are_assignable_on_the_role_form(): void
    {
        $assignable = RolePermissionUiCatalog::assignableNames('company');

        foreach (self::KITCHEN_ACTION_PERMISSIONS as $name) {
            $this->assertContains(
                $name,
                $assignable,
                "Role form cannot assign '{$name}' — it appears on no tab."
            );
        }
    }

    public function test_kitchen_modules_render_on_the_pos_tab(): void
    {
        $modules = RolePermissionUiCatalog::posModules();

        foreach (['kitchen production', 'kitchen recipes', 'kitchen production orders', 'kitchen production plans', 'kitchen requisitions', 'kitchen losses', 'kitchen reports', 'kitchen buffets', 'kitchen daily specials'] as $module) {
            $this->assertContains($module, $modules);
        }
    }
}
