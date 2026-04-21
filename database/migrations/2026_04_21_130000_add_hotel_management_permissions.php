<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    protected function hotelPermissionNames(): array
    {
        return [
            'manage hotel',
            'show hotel dashboard',
            'manage hotel room',
            'manage hotel reservation',
            'manage hotel frontdesk',
            'show hotel folio',
        ];
    }

    public function up(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->hotelPermissionNames() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $companyRole = Role::where('name', 'company')->where('guard_name', 'web')->first();
        if ($companyRole) {
            $companyRole->givePermissionTo($this->hotelPermissionNames());
        }
    }

    public function down(): void
    {
        // Keep permission data intact on rollback.
    }
};
