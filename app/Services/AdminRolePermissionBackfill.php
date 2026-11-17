<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Client issues 1 + 2 — backfill cost-centre and menu permissions for
 * admin-type roles. Called by migration 310007; kept as a service so
 * it is testable (migrations are not autoloaded).
 *
 * Root cause (issue 1): the cost-centre ACTION permissions match no
 * `{prefix} {module}` row in RolePermissionUiCatalog, so they never
 * appear on the role form — they can only ever be granted by the
 * seeder (the seeded `company` role has them) or by direct
 * assignment. Any custom Admin / Administrador role created through
 * the UI therefore lacks them, and both the Cost Centres menu and
 * the direct URL deny access even though the role holds the sidebar
 * grant.
 *
 * Root cause (issue 2): module action permissions and `menu.*`
 * sidebar grants are two SEPARATE checkbox sets on the role form
 * (by design — menu_can() falls back to legacy action permissions,
 * so the menu usually still shows). The data is what is wrong for
 * admin roles: whoever created/edited them ticked only some sidebar
 * boxes, so menus for modules the role can actually use stay hidden.
 * The fix is data, not a redesign: for every menu node whose legacy
 * action permissions the role already holds, grant the matching
 * `menu.*` permission. Menu grants are never removed here — revoking
 * an action permission must not unexpectedly hide menus.
 *
 * Scope: roles named Admin, Administrador or company, in EVERY
 * company. Cashier / Waiter / Manager / Bar / Kitchen roles are
 * untouched. Idempotent (skips grants the role already has).
 */
class AdminRolePermissionBackfill
{
    /** Cost-centre action permissions — the same 13 the seeded `company` role has. */
    public const COST_CENTRE_PERMISSIONS = [
        'view cost centres',
        'create cost centres',
        'edit cost centres',
        'deactivate cost centres',
        'manage cost centre allocations',
        'approve cost centre allocations',
        'manage asset cost centre assignments',
        'manage treasury dimensions',
        'manage gl dimensions',
        'view cost centre reports',
        'export cost centre reports',
        'manage cost centre budgets',
        'approve cost centre budgets',
    ];

    /** Admin-type role names that get the backfill, in every company. */
    public const ADMIN_ROLE_NAMES = ['Admin', 'Administrador', 'company'];

    /**
     * Client follow-up (2026-11-17) — Document Layouts + Plan/Referral
     * access for admin-type roles.
     *
     * Root cause: the Document Layouts controller gates on
     * 'manage document layouts' (or the matching menu.* grant, or
     * 'manage print settings', or type company) — but the
     * 'manage document layouts' permission ROW does not exist in
     * databases created before the seeder change that defines it
     * (UsersTableSeeder:159), so no role can hold it and the menu
     * item never appears for Administrador/Admin roles (Neyde got a
     * 403 on /document-layouts while the company owner saw the page).
     * Likewise 'manage plan' (PlanController@index gate) and the
     * menu.settings.plans / menu.settings.referral grants are only
     * on the seeded `company` role, so an Administrador never sees
     * the Plan or Referral Program menu items.
     *
     * Fix: create the missing permission row and grant these five to
     * every Admin / Administrador / company role, skipping grants
     * the role already holds. 'manage plan' only gates the plans
     * LIST (create/edit/buy use separate permissions), and the
     * Referral page has no controller gate — the menu grant is the
     * whole access. Idempotent. None of these five appears on the
     * role form (they match no RolePermissionUiCatalog module row),
     * so the role-save fix preserves them on edit.
     */
    public const LAYOUT_AND_PLAN_PERMISSIONS = [
        'manage document layouts',
        'menu.accounting_system.layout_management',
        'manage plan',
        'menu.settings.plans',
        'menu.settings.referral',
    ];

    public static function runLayoutAndPlanBackfill(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        foreach (self::LAYOUT_AND_PLAN_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        SidebarMenuPermissionCatalog::ensurePermissionsExist();

        foreach (Role::whereIn('name', self::ADMIN_ROLE_NAMES)->get() as $role) {
            $held = $role->permissions()->pluck('name')->all();
            $missing = array_values(array_diff(self::LAYOUT_AND_PLAN_PERMISSIONS, $held));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function run(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        foreach (self::COST_CENTRE_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        SidebarMenuPermissionCatalog::ensurePermissionsExist();

        $menuLegacy = self::subtreeLegacyMap();

        foreach (Role::whereIn('name', self::ADMIN_ROLE_NAMES)->get() as $role) {
            $held = $role->permissions()->pluck('name')->all();

            // 1. Cost-centre action permissions.
            $missing = array_values(array_diff(self::COST_CENTRE_PERMISSIONS, $held));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
                $held = array_merge($held, $missing);
            }

            // 2. Menu grants matching the action permissions the role holds.
            // A parent node qualifies when ANY permission in its whole
            // subtree is held (e.g. 'view cost centres' grants the
            // Cost Centres node AND the Accounting section above it).
            foreach ($menuLegacy as $menuPermission => $legacyNames) {
                if (in_array($menuPermission, $held, true)) {
                    continue;
                }
                foreach ($legacyNames as $legacy) {
                    if (in_array($legacy, $held, true)) {
                        $role->givePermissionTo($menuPermission);
                        $held[] = $menuPermission;
                        break;
                    }
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * menu permission => every legacy action permission in its subtree.
     *
     * @return array<string, array<int, string>>
     */
    protected static function subtreeLegacyMap(): array
    {
        $map = [];

        $collect = function (array $node) use (&$collect, &$map): array {
            $legacy = $node['legacy'] ?? [];
            foreach ($node['children'] ?? [] as $child) {
                $legacy = array_merge($legacy, $collect($child));
            }
            $map[$node['permission']] = array_values(array_unique($legacy));

            return $legacy;
        };

        foreach (SidebarMenuPermissionCatalog::tree() as $node) {
            $collect($node);
        }

        return $map;
    }
}
