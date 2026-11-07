<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §10).
 *
 * Extends the existing bank reconciliation sessions with the
 * file hash (repeat-file blocking, CA11), the close figures
 * (statement vs book vs outstanding vs explained) and the
 * close/reopen audit columns. All columns are nullable with safe
 * defaults — existing sessions keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliation_sessions', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('stored_path');
            $table->decimal('statement_balance', 20, 4)->nullable()->after('period_to');
            $table->decimal('book_balance', 20, 4)->nullable()->after('statement_balance');
            $table->decimal('outstanding_items', 20, 4)->nullable()->after('book_balance');
            $table->decimal('explained_differences', 20, 4)->nullable()->after('outstanding_items');
            $table->dateTime('closed_at')->nullable()->after('explained_differences');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            $table->dateTime('reopened_at')->nullable()->after('closed_by');
            $table->text('reopen_reason')->nullable()->after('reopened_at');

            // One file per account: a second session with the same
            // hash for the same bank account is a repeat import
            // (CA11). NULL hashes never collide in a MySQL unique
            // index, so legacy sessions without a hash are unaffected.
            $table->unique(['bank_account_id', 'file_hash'], 'brs_acct_hash_uq');
        });
    }

    public function down(): void
    {
        Schema::table('bank_reconciliation_sessions', function (Blueprint $table) {
            $table->dropUnique('brs_acct_hash_uq');
            $table->dropColumn([
                'file_hash',
                'statement_balance',
                'book_balance',
                'outstanding_items',
                'explained_differences',
                'closed_at',
                'closed_by',
                'reopened_at',
                'reopen_reason',
            ]);
        });
    }
};
