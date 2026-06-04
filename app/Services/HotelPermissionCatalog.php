<?php

namespace App\Services;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hotel Management Spatie permissions (names + role UI grouping).
 */
class HotelPermissionCatalog
{
    public const GUARD = 'web';

    /**
     * @return array<int, string>
     */
    public static function allNames(): array
    {
        $names = [];
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $permission) {
                $names[] = $permission['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<int, array{label: string, permissions: array<int, array{name: string, label: string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'label' => 'General',
                'permissions' => [
                    ['name' => 'manage hotel', 'label' => 'Full hotel management'],
                    ['name' => 'show hotel dashboard', 'label' => 'Hotel dashboard (view)'],
                ],
            ],
            [
                'label' => 'Rooms & rates',
                'permissions' => [
                    ['name' => 'manage hotel room', 'label' => 'Rooms, types, floors, rate plans (manage)'],
                    ['name' => 'show hotel room', 'label' => 'Rooms & setup (view)'],
                ],
            ],
            [
                'label' => 'Reservations',
                'permissions' => [
                    ['name' => 'manage hotel reservation', 'label' => 'Reservations & groups (manage)'],
                    ['name' => 'show hotel reservation', 'label' => 'Reservations & calendar (view)'],
                ],
            ],
            [
                'label' => 'Front desk & folio',
                'permissions' => [
                    ['name' => 'manage hotel frontdesk', 'label' => 'Check-in/out, housekeeping, night audit (manage)'],
                    ['name' => 'show hotel frontdesk', 'label' => 'Front desk & housekeeping (view)'],
                    ['name' => 'show hotel folio', 'label' => 'Guest folio (view)'],
                    ['name' => 'force hotel checkout', 'label' => 'Force checkout (override)'],
                ],
            ],
        ];
    }

    public static function ensurePermissionsExist(): void
    {
        foreach (self::allNames() as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => self::GUARD],
                ['name' => $name, 'guard_name' => self::GUARD]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Whether the permission map passed to role forms includes any hotel permission.
     *
     * @param  array<int|string, string>  $permissions  id => name from pluck
     */
    public static function hasAnyInPermissionMap(array $permissions): bool
    {
        $hotelNames = self::allNames();

        foreach ($permissions as $name) {
            if (in_array($name, $hotelNames, true)) {
                return true;
            }
        }

        return false;
    }
}
