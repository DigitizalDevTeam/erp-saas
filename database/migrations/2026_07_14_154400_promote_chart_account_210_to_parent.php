<?php

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Promote account 210 (Advance payment on account of purchases) to a top-level
 * parent account, displayed after 29 (numeric order: 29 → 210 → 31).
 * Previously it was nested under 21 because of code-prefix matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $accounts = ChartOfAccount::where('code', '210')->get();

        foreach ($accounts as $account) {
            $account->parent_account_id = null;
            $account->parent = 0;
            $account->save();

            if (! Schema::hasTable('chart_of_account_parents')) {
                continue;
            }

            ChartOfAccountParent::updateOrCreate(
                [
                    'account' => $account->id,
                    'created_by' => $account->created_by,
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
    }

    public function down(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $accounts = ChartOfAccount::where('code', '210')->get();

        foreach ($accounts as $account) {
            $parent21 = ChartOfAccount::where('created_by', $account->created_by)
                ->where('code', '21')
                ->first();

            if (! $parent21) {
                continue;
            }

            $legacyParentId = 0;
            if (Schema::hasTable('chart_of_account_parents')) {
                $link = ChartOfAccountParent::where('account', $parent21->id)
                    ->where('created_by', $account->created_by)
                    ->first();
                $legacyParentId = $link ? (int) $link->id : 0;

                ChartOfAccountParent::where('account', $account->id)
                    ->where('created_by', $account->created_by)
                    ->delete();
            }

            $account->parent_account_id = $parent21->id;
            $account->parent = $legacyParentId;
            $account->type = $parent21->type;
            $account->sub_type = $parent21->sub_type;
            $account->save();
        }
    }
};
