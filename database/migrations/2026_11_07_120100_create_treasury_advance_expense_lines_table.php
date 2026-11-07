<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 6 — advance expense substantiation lines
 * (plan §5, §6, §10, CA10).
 *
 * One line per expense the recipient claims against the advance:
 * date, counterparty, category, document reference, amount, the
 * chart account the expense posts to, an optional cost centre, and
 * evidence. Each line is reviewed on its own — approve, reject, or
 * return for correction (the recipient edits and resubmits).
 *
 * Approving a line posts one journal (Dr expense account / Cr
 * advance asset account) on its own operation, and moves at most
 * the advance balance still unsubstantiated: when the approved
 * expenses run past the paid amount, the excess is recorded on the
 * line as reimbursement_amount and becomes the advance's separate
 * reimbursement — it is never posted as an expense twice, and it
 * is paid only after its own approval (CA10).
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04).
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_advance_expense_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('advance_id');
            $table->integer('created_by');
            $table->date('expense_date');
            $table->string('counterparty', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('document', 150)->nullable();
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            // The expense account this line posts to when approved.
            $table->bigInteger('chart_account_id');
            $table->bigInteger('cost_centre_id')->nullable();
            $table->text('evidence')->nullable();
            // pending | approved | rejected | returned
            $table->string('state', 20)->default('pending');
            $table->bigInteger('submitted_by');
            $table->bigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->bigInteger('operation_id')->nullable();
            // The part of this line above the advance balance still
            // unsubstantiated — the advance's reimbursement, not a
            // second expense posting.
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            $table->timestamps();

            $table->index(['advance_id'], 'tael_advance_idx');
            $table->index(['advance_id', 'state'], 'tael_adv_state_idx');
            $table->index(['cost_centre_id'], 'tael_centre_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_advance_expense_lines');
    }
};
