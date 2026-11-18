<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bank Reconciliation Treasury dropdown (2026-11-19).
 *
 * Reversing a missing movement now marks its operation 'reversed',
 * but reversals made before that fix left their operations
 * 'confirmed' — so a reversed operation kept appearing in the
 * manual-match dropdown. A reversed operation is identifiable by
 * the reversal posting that points at it (reversal_of_id), even
 * when its movement was deleted together with its session. Flip
 * every such operation to 'reversed'. The down migration is a
 * no-op: the pre-fix state cannot be told apart from a genuinely
 * confirmed operation.
 */
class BackfillReversedTreasuryOperations extends Migration
{
    public function up(): void
    {
        // Two steps on purpose: MySQL refuses an UPDATE whose WHERE
        // subquery reads the same table (error 1093), and the SQLite
        // test suite would not catch that. Fetch the ids first, then
        // update by id.
        $reversedIds = DB::table('treasury_operations')
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id')
            ->all();

        if ($reversedIds === []) {
            return;
        }

        DB::table('treasury_operations')
            ->where('state', 'confirmed')
            ->whereIn('id', $reversedIds)
            ->update(['state' => 'reversed']);
    }

    public function down(): void
    {
        // No-op: see the class comment.
    }
}
