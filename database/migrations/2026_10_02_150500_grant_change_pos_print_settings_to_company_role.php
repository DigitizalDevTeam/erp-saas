<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Let the company role change restaurant POS printer settings.
     */
    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roles = config('permission.table_names.roles', 'roles');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');

        $permissionId = DB::table($permissions)
            ->where('name', 'change pos print settings')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table($roles)
            ->where('name', 'company')
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table($pivot)
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();
            if (! $exists) {
                DB::table($pivot)->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roles = config('permission.table_names.roles', 'roles');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');

        $permissionId = DB::table($permissions)
            ->where('name', 'change pos print settings')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table($roles)
            ->where('name', 'company')
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table($pivot)
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
