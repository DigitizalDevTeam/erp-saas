<?php

use App\Models\ChartOfAccount;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 443 VAT subtree:
 * - create missing 44324
 * - parent 44324 + 44341 under 4432
 * - parent 4461 under 443 (sibling of 4438/4439)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        ChartOfAccountHierarchy::syncMissingAccountsFromCsv();

        $creatorIds = ChartOfAccount::query()
            ->distinct()
            ->pluck('created_by');

        foreach ($creatorIds as $creatorId) {
            $creatorId = (int) $creatorId;
            if ($creatorId <= 0) {
                continue;
            }

            ChartOfAccountHierarchy::repairParentsByCodePrefix($creatorId);
        }
    }

    public function down(): void
    {
        // Data correction — no automatic rollback.
    }
};
