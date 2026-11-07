<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 3 — billing renewals.
 *
 * One row per renewal event: an old contract renewed into a new
 * contract (the renewal draft). The new contract is a normal
 * billing contract in draft state — same customer, lines,
 * frequency and terms, new start date (old end date + 1 day),
 * new reference — and it goes through the same approval flow
 * as any new contract. A renewal never re-bills periods already
 * invoiced under the old contract: the new contract's
 * occurrences start at its own start date. Status: pending
 * (draft created, not yet active), renewed (the new contract
 * became active), not_renewed (the renewal draft was abandoned
 * — the old contract simply ends).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_renewals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('new_contract_id');
            $table->date('effective_date');
            $table->string('type', 20)->default('manual');
            $table->string('status', 20)->default('pending');
            $table->timestamp('notice_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['new_contract_id'], 'bren_new_contract_uq');
            $table->index(['contract_id'], 'bren_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_renewals');
    }
};
