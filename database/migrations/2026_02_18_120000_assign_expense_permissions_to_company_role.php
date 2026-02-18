<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Expense & expense category permission names to assign to company role.
     */
    protected function expensePermissionNames(): array
    {
        return [
            'manage expense',
            'create expense',
            'edit expense',
            'delete expense',
            'approve expense',
            'reject expense',
            'manage expense category',
            'create expense category',
            'edit expense category',
            'delete expense category',
        ];
    }

    /**
     * Run the migrations.
     * Ensure expense permissions exist and assign them to the company role.
     */
    public function up(): void
    {
        $guard = 'web';
        $now = now();
        $table = config('permission.table_names.permissions', 'permissions');

        foreach ($this->expensePermissionNames() as $name) {
            $exists = DB::table($table)->where('name', $name)->where('guard_name', $guard)->exists();
            if (!$exists) {
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
            $companyRole->givePermissionTo($this->expensePermissionNames());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $companyRole = Role::where('name', 'company')->where('guard_name', 'web')->first();
        if ($companyRole) {
            $companyRole->revokePermissionTo($this->expensePermissionNames());
        }
    }
};
