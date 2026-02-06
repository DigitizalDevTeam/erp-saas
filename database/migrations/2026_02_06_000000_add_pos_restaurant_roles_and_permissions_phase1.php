<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Services\PosRestaurantRoleService;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 1: Create POS Restaurant permissions and roles (Cashier, Waiter, Manager)
     * for all existing companies. New companies get these via PosRestaurantRoleService
     * in registration and UserController.
     */
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        $now = now();
        foreach (PosRestaurantRoleService::permissionNames() as $name) {
            $exists = DB::table($table)->where('name', $name)->where('guard_name', 'web')->exists();
            if (!$exists) {
                DB::table($table)->insert([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        PosRestaurantRoleService::createForAllExistingCompanies();
    }

    /**
     * Reverse the migrations.
     * We do not drop permissions or roles on rollback to avoid breaking companies
     * that may have assigned users to these roles.
     */
    public function down(): void
    {
        // No-op: leave roles and permissions in place.
    }
};
