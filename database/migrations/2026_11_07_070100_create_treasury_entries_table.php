<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 1 — confirmed money movements (plan §5).
 *
 * One entry per confirmed operation leg: the bank movement the
 * operation posted. `journal_id` links the movement to the
 * JournalEntry it posted (through JournalEntryPoster, never
 * hand-written). A reversal is a NEW entry with negated amounts
 * linked via `reversal_of_id` — never an edit of the old one (RF03).
 *
 * Allocation journals (credit-liability <-> AR) are GL-only and
 * create NO entry here: only bank movements change the posted
 * balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('financial_account_id');
            // inflow / outflow
            $table->string('direction', 10);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['operation_id'], 'te_operation_idx');
            $table->index(['financial_account_id', 'direction'], 'te_account_dir_idx');
            $table->index(['journal_id'], 'te_journal_idx');
            $table->index(['reversal_of_id'], 'te_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_entries');
    }
};
