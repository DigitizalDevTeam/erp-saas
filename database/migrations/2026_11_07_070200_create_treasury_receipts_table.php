<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 1 — receipts (plan §5).
 *
 * Workflow: DRAFT -> PENDING -> CONFIRMED; PENDING can be REJECTED
 * or CANCELLED. Confirm only from authorised evidence; a receipt
 * number is issued only after confirmation. A confirmed receipt is
 * corrected only by an authorised reversal (new row, negated
 * amounts, linked via `reversal_of_id`) — never by editing (RF03).
 *
 * Amount model (plan §7): `amount_original` is the gross amount the
 * customer paid, before bank fees and withholding. Cash credited to
 * the bank is gross - fees - withholding. The settlement value
 * (what is applied to invoices) is gross + discount: the discount
 * granted is added to the settlement, the fee/withholding are the
 * company's own lines. Unapplied credit = settlement value -
 * allocated.
 *
 * A receipt without an invoice needs a classification and a reason.
 * Evidence uploaded while pending changes neither cash nor debt
 * until confirmation (CA04) — nothing posts before confirm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('customer_id')->nullable();
            // cash / transfer / deposit / cheque / card / mobile_wallet
            $table->string('method', 20)->default('transfer');
            $table->string('external_transaction_id', 100)->nullable();
            $table->date('value_date')->nullable();
            $table->string('cheque_bank', 100)->nullable();
            $table->string('cheque_number', 50)->nullable();
            $table->decimal('fees', 20, 4)->default(0);
            $table->decimal('withholding', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            // draft / pending / confirmed / rejected / cancelled / reversed
            $table->string('state', 20)->default('draft');
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            // Issued only after confirmation (== the operation reference).
            $table->string('receipt_number', 60)->nullable();
            // Classification + reason when the receipt has no invoice.
            $table->string('classification', 50)->nullable();
            $table->text('classification_reason')->nullable();
            $table->text('evidence')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['operation_id'], 'tr_operation_idx');
            $table->index(['created_by', 'state'], 'tr_company_state_idx');
            $table->index(['customer_id', 'state'], 'tr_customer_state_idx');
            $table->index(['receipt_number'], 'tr_number_idx');
            $table->index(['reversal_of_id'], 'tr_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_receipts');
    }
};
