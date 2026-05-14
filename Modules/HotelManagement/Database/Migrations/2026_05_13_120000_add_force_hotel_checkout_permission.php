<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private function permissionName(): string
    {
        return 'force hotel checkout';
    }

    public function up(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name' => $this->permissionName(),
            'guard_name' => 'web',
        ]);

        $companyRole = Role::where('name', 'company')->where('guard_name', 'web')->first();
        if ($companyRole) {
            $companyRole->givePermissionTo($this->permissionName());
        }
    }

    public function down(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', $this->permissionName())->where('guard_name', 'web')->delete();
    }
};
