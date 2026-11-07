<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §10).
 *
 * API import sync cursors: one row per (bank account, source).
 * A re-run resumes after the stored cursor and never re-imports
 * a line. Statement lines also gain an external_id so an
 * imported line can be recognised across sessions of the same
 * account (dedupe for CA11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_statement_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('source_key', 64);
            $table->string('cursor_value', 255)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->unique(['bank_account_id', 'source_key'], 'tssc_acct_src_uq');
            $table->index(['created_by'], 'tssc_company_idx');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->string('external_id', 128)->nullable()->after('row_index');
            $table->index(['external_id'], 'bsl_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropIndex('bsl_external_idx');
            $table->dropColumn('external_id');
        });

        Schema::dropIfExists('treasury_statement_sync_cursors');
    }
};
