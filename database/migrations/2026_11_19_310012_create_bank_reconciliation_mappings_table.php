<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank Reconciliation Step 2 usability (2026-11-19).
 *
 * Remembers the last column mapping used for each bank account, so
 * the next import for the same account starts from the user's own
 * mapping instead of the generic defaults. One saved mapping per
 * (bank account, company): re-importing overwrites it with the
 * mapping that was just used. The mapping JSON uses the same
 * normalized shape as bank_reconciliation_sessions.column_mapping
 * (0-based data_start_row, header_row, date_col, description_col,
 * amount_mode, amount_col, debit_col, credit_col, balance_col).
 */
class CreateBankReconciliationMappingsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_reconciliation_mappings')) {
            return;
        }

        Schema::create('bank_reconciliation_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedInteger('created_by');
            $table->string('preset_key', 64)->nullable();
            $table->json('mapping')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['bank_account_id', 'created_by'], 'brm_acct_creator_uq');
            $table->index('bank_account_id', 'brm_acct_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_mappings');
    }
}
