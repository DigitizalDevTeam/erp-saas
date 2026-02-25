<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add "give pos credit" permission so only admin or explicitly allowed users
     * (e.g. duty manager) can use Credit payment at POS. Credit terms (days) are
     * set in back office; cashiers cannot grant credit by default.
     */
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        $exists = DB::table($table)->where('name', 'give pos credit')->where('guard_name', 'web')->exists();
        if (!$exists) {
            DB::table($table)->insert([
                'name' => 'give pos credit',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        DB::table($table)->where('name', 'give pos credit')->where('guard_name', 'web')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
