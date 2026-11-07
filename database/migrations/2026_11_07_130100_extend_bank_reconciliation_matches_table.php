<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §10).
 *
 * A match can now link to a Treasury operation as well as to a
 * Transaction, and it carries the matched amount so partial and
 * multiple matches stay within the statement and movement
 * residuals (CA12).
 *
 * The legacy unique (session_id, statement_line_id) is dropped:
 * one statement line may now match several movements (one-to-many),
 * and one movement may match several lines (many-to-one). The
 * legacy one-to-one Transaction behaviour is unchanged — the
 * (session_id, transaction_id) unique stays, and the service
 * layer refuses to over-allocate any movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliation_matches', function (Blueprint $table) {
            // Laravel's default name for the legacy unique is exactly
            // 64 characters, so it exists under this name on MySQL too.
            $table->dropUnique('bank_reconciliation_matches_session_id_statement_line_id_unique');
            $table->index(['statement_line_id'], 'brm_line_idx');
            $table->unsignedBigInteger('treasury_operation_id')->nullable()->after('transaction_id');
            $table->decimal('matched_amount', 20, 4)->nullable()->after('treasury_operation_id');
            $table->index(['treasury_operation_id'], 'brm_top_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliation_matches', function (Blueprint $table) {
            $table->dropIndex('brm_top_idx');
            $table->dropColumn(['treasury_operation_id', 'matched_amount']);
            $table->dropIndex('brm_line_idx');
            $table->unique(['session_id', 'statement_line_id']);
        });
    }
};
