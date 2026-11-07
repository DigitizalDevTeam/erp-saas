<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §10).
 *
 * Items found on the bank statement but not in the books (bank
 * fees, interest, other charges): proposed from a statement line,
 * confirmed with a category + offset chart account + authorisation,
 * posted once through the JournalEntryPoster, and linked to the
 * statement line so the same item can never be created twice.
 * A confirmed movement is immutable — reversal only, linked to
 * the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_missing_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('statement_line_id');
            $table->string('category', 32); // fee | interest | other
            $table->unsignedBigInteger('offset_account_id');
            $table->string('currency', 8)->default('MZN');
            $table->decimal('amount_original', 20, 4);
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->decimal('amount_base', 20, 4);
            $table->string('state', 24)->default('proposed'); // proposed | confirmed | rejected | reversed
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('reversal_operation_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['session_id'], 'tmm_session_idx');
            $table->index(['statement_line_id'], 'tmm_line_idx');
            $table->index(['created_by', 'state'], 'tmm_company_idx');
            $table->index(['operation_id'], 'tmm_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_missing_movements');
    }
};
