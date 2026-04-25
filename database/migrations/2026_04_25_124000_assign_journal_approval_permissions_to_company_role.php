<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    protected function journalApprovalPermissionNames(): array
    {
        return [
            'approve journal entry',
            'reject journal entry',
        ];
    }

    public function up(): void
    {
        $guard = 'web';
        $now = now();
        $table = config('permission.table_names.permissions', 'permissions');

        foreach ($this->journalApprovalPermissionNames() as $name) {
            $exists = DB::table($table)->where('name', $name)->where('guard_name', $guard)->exists();
            if (! $exists) {
                DB::table($table)->insert([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $companyRole = Role::where('name', 'company')->where('guard_name', $guard)->first();
        if ($companyRole) {
            $companyRole->givePermissionTo($this->journalApprovalPermissionNames());
        }
    }

    public function down(): void
    {
        $companyRole = Role::where('name', 'company')->where('guard_name', 'web')->first();
        if ($companyRole) {
            $companyRole->revokePermissionTo($this->journalApprovalPermissionNames());
        }
    }
};
