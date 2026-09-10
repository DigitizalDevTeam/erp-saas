<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Align competencies permission names with Gate::check('manage competencies') casing.
     * Uses BINARY compares + two-step rename because MySQL utf8mb4_*_ci treats the names as equal.
     */
    public function up(): void
    {
        $renames = [
            'Manage Competencies' => 'manage competencies',
            'Create Competencies' => 'create competencies',
            'Edit Competencies' => 'edit competencies',
            'Delete Competencies' => 'delete competencies',
        ];

        foreach ($renames as $from => $to) {
            $this->renamePermissionCase($from, $to);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $renames = [
            'manage competencies' => 'Manage Competencies',
            'create competencies' => 'Create Competencies',
            'edit competencies' => 'Edit Competencies',
            'delete competencies' => 'Delete Competencies',
        ];

        foreach ($renames as $from => $to) {
            $this->renamePermissionCase($from, $to);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function renamePermissionCase(string $from, string $to): void
    {
        $existing = DB::table('permissions')
            ->whereRaw('BINARY `name` = ?', [$from])
            ->where('guard_name', 'web')
            ->first();

        if (! $existing) {
            return;
        }

        if ($existing->name === $to) {
            return;
        }

        $tmp = $to . '.__tmp__';
        DB::table('permissions')->where('id', $existing->id)->update(['name' => $tmp]);
        DB::table('permissions')->where('id', $existing->id)->update(['name' => $to]);
    }
};
