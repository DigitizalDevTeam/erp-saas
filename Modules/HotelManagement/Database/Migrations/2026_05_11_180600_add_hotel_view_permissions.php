<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private function permissionNames(): array
    {
        return [
            'show hotel room',
            'show hotel reservation',
            'show hotel frontdesk',
        ];
    }

    public function up(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissionNames() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $companyRole = Role::where('name', 'company')->where('guard_name', 'web')->first();
        if ($companyRole) {
            $companyRole->givePermissionTo($this->permissionNames());
        }
    }

    public function down(): void
    {
        // Keep permission data intact on rollback.
    }
};
