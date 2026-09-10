<?php

use App\Services\SidebarMenuPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        SidebarMenuPermissionCatalog::ensurePermissionsExist();

        $names = SidebarMenuPermissionCatalog::allPermissionNames();
        $permissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        // Grant to global "company" role and every company-created role named company
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->where(function ($q) {
                $q->where('name', 'company')
                    ->orWhere(function ($q2) {
                        $q2->where('name', 'company')->whereNotNull('created_by');
                    });
            })
            ->get();

        // Also grant to all roles belonging to company owners (created_by = company user id)
        // so company admins keep full sidebar after introduce menu.*
        $companyUserIds = DB::table('users')->where('type', 'company')->pluck('id');
        $companyOwnedRoles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('created_by', $companyUserIds)
            ->where('name', 'company')
            ->get();

        $allRoles = $roles->merge($companyOwnedRoles)->unique('id');

        foreach ($allRoles as $role) {
            $role->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = SidebarMenuPermissionCatalog::allPermissionNames();

        $roles = Role::query()->where('guard_name', 'web')->get();
        foreach ($roles as $role) {
            $role->revokePermissionTo($names);
        }

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->where('name', 'like', 'menu.%')
            ->delete();

        // Do not delete hotel.menu.* — those pre-existed

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
