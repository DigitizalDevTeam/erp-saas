<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 1 — billing occurrences (plan §5, §6).
 *
 * One row per scheduled billing period: coverage start (inclusive)
 * + coverage end (exclusive, displayed as the inclusive final
 * day), scheduled billing date, due date, amount, state.
 *
 * The unique constraint on (created_by, contract_id,
 * occurrence_key) is the duplicate guard (AC03): a double run or
 * a crashed run can never create a second occurrence for the same
 * period. occurrence_key is deterministic —
 * {reference}:{period}:{purpose} — so firstOrCreate and the
 * unique index agree.
 *
 * States: pending (generated, awaiting its billing date) |
 * generated (picked up by the engine — Phase 2) | invoiced |
 * skipped (partial period, by explicit choice, or a suspended
 * period) | cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_contract_occurrences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('created_by');
            $table->string('occurrence_key', 80);
            $table->string('period_key', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('billing_date');
            $table->date('due_date');
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            // invoice | (future purposes: reminder, renewal)
            $table->string('purpose', 20)->default('invoice');
            $table->bigInteger('invoice_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'contract_id', 'occurrence_key'], 'bco_occ_key_uq');
            $table->index(['contract_id', 'billing_date'], 'bco_contract_date_idx');
            $table->index(['created_by', 'state'], 'bco_creator_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_contract_occurrences');
    }
};
