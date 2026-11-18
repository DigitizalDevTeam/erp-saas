<?php

use App\Services\AdminRolePermissionBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Client follow-up (2026-11-19) — Neyde (Colosso 'Admin',
 * MMCEMENT 'Administrador') sees only Cost Centres / Contracts /
 * Bank Reconciliation: her roles hold none of the Kitchen
 * Production, Treasury or Fixed Assets permissions, which live
 * only on the seeded `company` role. The sidebar hides any
 * module whose menu.* grant the role lacks, so all three modules
 * are invisible to her — and to every other admin-type role.
 *
 * The fix follows the 310007/310009/310010 backfill pattern:
 * grant the full 89-permission module set (actions + menu.*)
 * to every role named Admin / Administrador / Administrator /
 * company, in every company, skipping grants the role already
 * holds. Idempotent. Both of Neyde's roles are covered by name
 * ('Admin' in Colosso, 'Administrador' in MMCEMENT).
 */
class BackfillAdminNewModulePermissions extends Migration
{
    public function up(): void
    {
        AdminRolePermissionBackfill::runNewModuleBackfill();
    }

    public function down(): void
    {
        // Intentional no-op: this is a data repair, not a schema change.
        // Removing grants on rollback could strip permissions a role
        // held BEFORE the migration ran.
    }
}
