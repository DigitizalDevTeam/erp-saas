<?php

use App\Services\AdminRolePermissionBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Client follow-up (2026-11-18) — roles named 'Administrator' were
 * missed by migrations 310007 and 310009: both backfills match role
 * names exactly, and ADMIN_ROLE_NAMES listed only Admin /
 * Administrador / company. On the test site that left 3 admin users
 * (roles 10 and 194) without the Document Layout, Plan and Referral
 * permissions — Azmira (Hoya) got 403 on /document-layouts while
 * Neyde (Administrador) and Danilo (company) worked.
 *
 * The fix is the extended name list; this migration re-runs both
 * backfill entry points so those roles converge with the other admin
 * roles. Both are idempotent (existing grants are skipped).
 */
class BackfillAdministratorRolePermissions extends Migration
{
    public function up(): void
    {
        AdminRolePermissionBackfill::run();
        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();
    }

    public function down(): void
    {
        // Intentional no-op: this is a data repair, not a schema change.
        // Removing grants on rollback could strip permissions a role
        // held BEFORE the migration ran.
    }
}
