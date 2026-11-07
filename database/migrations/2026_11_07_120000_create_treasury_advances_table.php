<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 6 — advances (plan §5, §6, §10, CA10).
 *
 * An advance is money paid out to an employee or a supplier before
 * the expense is known: request → approval → disbursement → expense
 * substantiation (per line, with evidence) → regularisation (return
 * of unspent money, or a separate reimbursement when approved
 * expenses exceed the advance) → closure. Only when the balance is
 * regularised and every line is resolved can the advance close.
 *
 * The advance is the source of truth for the running figures:
 * amount_paid (disbursed), amount_substantiated (approved lines,
 * capped at the paid amount — the excess becomes a reimbursement),
 * amount_returned (unspent money handed back) and
 * amount_outstanding = paid − substantiated − returned. The
 * substantiated, returned and outstanding figures are recomputed
 * from the lines + returns by the service after every mutation, so
 * they can never drift from the rows that produced them.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04).
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_advances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            // The disbursement operation (draft at request, reference
            // allocated at disbursement — a cancelled draft never
            // consumes a number).
            $table->bigInteger('operation_id')->nullable();
            $table->bigInteger('source_account_id');
            // employee | supplier
            $table->string('recipient_type', 20);
            $table->bigInteger('recipient_id');
            // Name snapshot — the recipient row may be renamed later.
            $table->string('recipient_name', 150)->nullable();
            $table->text('purpose')->nullable();
            // Currency comes from the source account, never from the
            // form. Foreign currency needs a rate + source + date
            // (convertToBase) — a missing rate blocks the request,
            // never guessed (D2).
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_requested', 20, 4);
            $table->decimal('amount_requested_base', 20, 4);
            $table->decimal('amount_approved', 20, 4)->nullable();
            $table->decimal('amount_approved_base', 20, 4)->nullable();
            $table->decimal('amount_paid', 20, 4)->default(0);
            $table->decimal('amount_paid_base', 20, 4)->default(0);
            $table->decimal('amount_substantiated', 20, 4)->default(0);
            $table->decimal('amount_substantiated_base', 20, 4)->default(0);
            $table->decimal('amount_returned', 20, 4)->default(0);
            $table->decimal('amount_returned_base', 20, 4)->default(0);
            $table->decimal('amount_outstanding', 20, 4)->default(0);
            $table->decimal('amount_outstanding_base', 20, 4)->default(0);
            // Approved expenses above the paid amount (spec example:
            // advance 5,000, expenses 5,300 → 300 reimbursement).
            // A reimbursement is a separate item, subject to its own
            // approval, and paid only after approval.
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            // none | pending | approved | rejected | paid
            $table->string('reimbursement_state', 20)->default('none');
            $table->bigInteger('reimbursement_approved_by')->nullable();
            $table->timestamp('reimbursement_approved_at')->nullable();
            $table->timestamp('reimbursement_paid_at')->nullable();
            $table->bigInteger('reimbursement_journal_id')->nullable();
            $table->bigInteger('reimbursement_operation_id')->nullable();
            $table->text('reimbursement_reason')->nullable();
            $table->date('due_date')->nullable();
            // draft | pending_approval | approved | rejected |
            // disbursed | partially_substantiated | under_review |
            // regularised | closed | cancelled
            $table->string('state', 20)->default('draft');
            $table->bigInteger('requested_by');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->bigInteger('disbursement_journal_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('closed_by')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'state'], 'ta_company_state_idx');
            $table->index(['source_account_id'], 'ta_source_idx');
            $table->index(['recipient_type', 'recipient_id'], 'ta_recipient_idx');
            $table->index(['operation_id'], 'ta_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_advances');
    }
};
