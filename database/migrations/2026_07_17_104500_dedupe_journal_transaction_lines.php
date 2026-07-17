<?php

use App\Models\TransactionLines;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove duplicate Journal transaction_lines (same journal item posted twice).
 * This doubled year-end closing amounts and kept cleared P&L accounts visible in Trial Balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaction_lines')) {
            return;
        }

        $groups = DB::table('transaction_lines')
            ->select(
                'created_by',
                'reference_id',
                'reference_sub_id',
                DB::raw('MIN(id) as keep_id'),
                DB::raw('COUNT(*) as row_count')
            )
            ->where('reference', 'Journal')
            ->whereNotNull('reference_sub_id')
            ->groupBy('created_by', 'reference_id', 'reference_sub_id')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($groups as $group) {
            TransactionLines::query()
                ->where('reference', 'Journal')
                ->where('created_by', (int) $group->created_by)
                ->where('reference_id', (int) $group->reference_id)
                ->where('reference_sub_id', (int) $group->reference_sub_id)
                ->where('id', '!=', (int) $group->keep_id)
                ->delete();
        }
    }

    public function down(): void
    {
        // Data repair — no rollback.
    }
};
