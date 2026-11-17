<?php

use App\Services\AdminRolePermissionBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Client follow-up (2026-11-17) — Document Layouts + Plan/Referral
 * access for admin-type roles. The logic lives in
 * AdminRolePermissionBackfill (testable — migrations are not
 * autoloaded); this migration is the delivery mechanism. See the
 * service for the root causes and scope.
 */
class BackfillAdminDocumentLayoutPlanPermissions extends Migration
{
    public function up(): void
    {
        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();
    }

    public function down(): void
    {
        // Intentional no-op: this is a data repair, not a schema change.
        // Removing grants on rollback could strip permissions a role
        // held BEFORE the migration ran (e.g. the seeded `company`
        // role's plan and layout-menu permissions).
    }
}
