<?php

use App\Services\AdminRolePermissionBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Company backup (2026-11-25) — the "Download Your Backup" page
 * under Settings is visible only to users with the Admin role.
 * Grant 'download company backup' (and its 'menu.settings.backup'
 * sidebar grant) to every role named Admin / Administrador /
 * Administrator / company, in every company, skipping grants the
 * role already holds. Idempotent — the same pattern as the 310014
 * new-module backfill.
 */
class GrantCompanyBackupPermissionToAdminRoles extends Migration
{
    public function up(): void
    {
        AdminRolePermissionBackfill::grantCompanyBackupPermission();
    }

    public function down(): void
    {
        // Intentional no-op: this is a data grant, not a schema change.
        // Removing grants on rollback could strip permissions a role
        // held BEFORE the migration ran.
    }
}
