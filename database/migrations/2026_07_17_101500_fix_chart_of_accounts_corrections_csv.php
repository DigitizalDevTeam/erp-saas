<?php

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Apply client corrections from chart_new_data.csv:
 * - 4438/4439 and 45xx series → Assets / Current Asset (were wrongly Liabilities)
 * - Rebuild parent tree (Sub Account - Parent, Sub sub Account, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $creatorIds = ChartOfAccount::query()
            ->distinct()
            ->pluck('created_by');

        foreach ($creatorIds as $creatorId) {
            $creatorId = (int) $creatorId;
            if ($creatorId <= 0) {
                continue;
            }

            $this->promoteParentAccount('45', $creatorId);
            ChartOfAccountHierarchy::repairParentsByCodePrefix($creatorId);
            ChartOfAccountHierarchy::applyCsvCategories($creatorId);
        }
    }

    public function down(): void
    {
        // Data correction — no automatic rollback.
    }

    private function promoteParentAccount(string $code, int $creatorId): void
    {
        $account = ChartOfAccount::where('created_by', $creatorId)
            ->where('code', $code)
            ->first();

        if (! $account) {
            return;
        }

        $account->parent_account_id = null;
        $account->parent = 0;
        $account->save();

        if (! Schema::hasTable('chart_of_account_parents')) {
            return;
        }

        ChartOfAccountParent::updateOrCreate(
            [
                'account' => $account->id,
                'created_by' => $creatorId,
            ],
            [
                'name' => $account->name,
                'name_fr' => $account->name_fr,
                'name_zh' => $account->name_zh,
                'name_es' => $account->name_es,
                'name_pt_br' => $account->name_pt_br,
                'type' => $account->type,
                'sub_type' => $account->sub_type,
            ]
        );
    }
};
