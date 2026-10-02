<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the role permission that allows changing restaurant POS printer settings.
     */
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        $exists = DB::table($table)->where('name', 'change pos print settings')->where('guard_name', 'web')->exists();
        if (! $exists) {
            DB::table($table)->insert([
                'name' => 'change pos print settings',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');
        DB::table($table)->where('name', 'change pos print settings')->where('guard_name', 'web')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
