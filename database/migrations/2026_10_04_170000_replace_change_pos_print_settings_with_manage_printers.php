<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace the POS-only "change pos print settings" permission with the
     * global "manage printers" permission. Roles (and direct user grants)
     * that had the old permission receive the new one, then the old
     * permission is removed from Roles entirely.
     */
    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $rolesPivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $modelPivot = config('permission.table_names.model_has_permissions', 'model_has_permissions');

        $newId = DB::table($permissions)
            ->where('name', 'manage printers')
            ->where('guard_name', 'web')
            ->value('id');
        if (! $newId) {
            $newId = DB::table($permissions)->insertGetId([
                'name' => 'manage printers',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $oldId = DB::table($permissions)
            ->where('name', 'change pos print settings')
            ->where('guard_name', 'web')
            ->value('id');

        if ($oldId) {
            foreach (DB::table($rolesPivot)->where('permission_id', $oldId)->get() as $row) {
                $exists = DB::table($rolesPivot)
                    ->where('permission_id', $newId)
                    ->where('role_id', $row->role_id)
                    ->exists();
                if (! $exists) {
                    DB::table($rolesPivot)->insert([
                        'permission_id' => $newId,
                        'role_id' => $row->role_id,
                    ]);
                }
            }

            if (DB::getSchemaBuilder()->hasTable($modelPivot)) {
                foreach (DB::table($modelPivot)->where('permission_id', $oldId)->get() as $row) {
                    $exists = DB::table($modelPivot)
                        ->where('permission_id', $newId)
                        ->where('model_type', $row->model_type)
                        ->where('model_id', $row->model_id)
                        ->exists();
                    if (! $exists) {
                        DB::table($modelPivot)->insert([
                            'permission_id' => $newId,
                            'model_type' => $row->model_type,
                            'model_id' => $row->model_id,
                        ]);
                    }
                }
                DB::table($modelPivot)->where('permission_id', $oldId)->delete();
            }

            DB::table($rolesPivot)->where('permission_id', $oldId)->delete();
            DB::table($permissions)->where('id', $oldId)->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $rolesPivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $modelPivot = config('permission.table_names.model_has_permissions', 'model_has_permissions');

        $oldId = DB::table($permissions)
            ->where('name', 'change pos print settings')
            ->where('guard_name', 'web')
            ->value('id');
        if (! $oldId) {
            $oldId = DB::table($permissions)->insertGetId([
                'name' => 'change pos print settings',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $newId = DB::table($permissions)
            ->where('name', 'manage printers')
            ->where('guard_name', 'web')
            ->value('id');

        if ($newId) {
            foreach (DB::table($rolesPivot)->where('permission_id', $newId)->get() as $row) {
                $exists = DB::table($rolesPivot)
                    ->where('permission_id', $oldId)
                    ->where('role_id', $row->role_id)
                    ->exists();
                if (! $exists) {
                    DB::table($rolesPivot)->insert([
                        'permission_id' => $oldId,
                        'role_id' => $row->role_id,
                    ]);
                }
            }

            if (DB::getSchemaBuilder()->hasTable($modelPivot)) {
                foreach (DB::table($modelPivot)->where('permission_id', $newId)->get() as $row) {
                    $exists = DB::table($modelPivot)
                        ->where('permission_id', $oldId)
                        ->where('model_type', $row->model_type)
                        ->where('model_id', $row->model_id)
                        ->exists();
                    if (! $exists) {
                        DB::table($modelPivot)->insert([
                            'permission_id' => $oldId,
                            'model_type' => $row->model_type,
                            'model_id' => $row->model_id,
                        ]);
                    }
                }
                DB::table($modelPivot)->where('permission_id', $newId)->delete();
            }

            DB::table($rolesPivot)->where('permission_id', $newId)->delete();
            DB::table($permissions)->where('id', $newId)->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
