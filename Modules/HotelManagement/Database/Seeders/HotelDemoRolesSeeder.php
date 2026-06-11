<?php

namespace Modules\HotelManagement\Database\Seeders;

use App\Services\HotelPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Recommended hotel role presets for client demos and permission QA.
 *
 * Run: php artisan db:seed --class="Modules\HotelManagement\Database\Seeders\HotelDemoRolesSeeder"
 */
class HotelDemoRolesSeeder extends Seeder
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function roleMap(): array
    {
        return [
            'Hotel Manager' => HotelPermissionCatalog::allNames(),
            'Hotel Front Desk' => [
                'show hotel dashboard',
                'show hotel reservation',
                'manage hotel reservation',
                'manage hotel frontdesk',
                'show hotel folio',
            ],
            'Hotel Front Desk (view)' => [
                'show hotel dashboard',
                'show hotel reservation',
                'show hotel frontdesk',
                'show hotel folio',
            ],
            'Hotel Housekeeping' => [
                'show hotel frontdesk',
                'manage hotel frontdesk',
            ],
            'Hotel Rooms Setup' => [
                'show hotel room',
                'manage hotel room',
            ],
        ];
    }

    public function run(): void
    {
        HotelPermissionCatalog::ensurePermissionsExist();

        foreach ($this->roleMap() as $roleName => $permissions) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => HotelPermissionCatalog::GUARD],
                ['name' => $roleName, 'guard_name' => HotelPermissionCatalog::GUARD]
            );

            $role->syncPermissions(
                Permission::where('guard_name', HotelPermissionCatalog::GUARD)
                    ->whereIn('name', $permissions)
                    ->pluck('name')
                    ->all()
            );

            $this->command?->info("Role synced: {$roleName} (" . count($permissions) . ' permissions)');
        }
    }
}
