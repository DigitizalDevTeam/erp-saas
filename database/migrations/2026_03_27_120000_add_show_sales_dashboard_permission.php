<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Add "show sales dashboard" permission and assign to company role.
     */
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        if (!DB::getSchemaBuilder()->hasTable($table)) {
            return;
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'show sales dashboard', 'guard_name' => 'web']);
        $companyRole = Role::where('name', 'company')->first();

        if ($companyRole && !$companyRole->hasPermissionTo($permission)) {
            $companyRole->givePermissionTo($permission);
        }
    }

    /**
     * Keep permission data intact on rollback.
     */
    public function down(): void
    {
        // No-op: do not remove permissions on rollback.
    }
};
