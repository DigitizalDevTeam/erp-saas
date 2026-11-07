<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Treasury payment-line dimensions (Cost Centre Phase 3, plan §5/§7).
        //
        // One row per (payment, cost centre). A BillPayment IS one payment
        // line (one bill, one amount, one bank account), so payment_id +
        // payment_type identify the line; when the Treasury module lands
        // with real payment lines, its ids go in as payment_type
        // 'treasury_payment'. Receipts (customer InvoicePayment rows)
        // are tagged the same way, for cash-flow analysis only.
        //
        // Dimensions sit BESIDE the money: these rows never create,
        // change or delete a journal line, a bank movement or a balance.
        // The bank movement for a payment is recorded exactly once, by
        // BillController::createPayment / DocumentLedgerService — this
        // table only says WHICH cost centres that cash belongs to.
        Schema::create('treasury_line_dimensions', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            // bill_payment | receipt | treasury_payment
            $table->string('payment_type', 20);
            // The BillPayment / InvoicePayment / future treasury payment id.
            $table->unsignedBigInteger('payment_id');
            // The bill the payment settles (bill payments only).
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            // Second dimension (plan §5): independent of the centre.
            $table->unsignedBigInteger('project_id')->nullable();
            // Future Fixed Assets integration (plan §5): stays null in Phase 3.
            $table->unsignedBigInteger('asset_id')->nullable();
            // The actual rounded amount for this centre — persisted, never
            // recomputed. Rows for one payment sum to at most the payment
            // amount (less when part of the bill was never allocated).
            $table->decimal('allocated_amount', 18, 2);
            // The split % that produced the amount, when the row came from
            // a percentage split (null for fixed-amount splits).
            $table->decimal('percentage', 9, 6)->nullable();
            // inherited | manual — how the row was produced.
            $table->string('source', 20)->default('inherited');
            // active | superseded — an override supersedes the old set
            // (reverse-and-repost pattern: posted rows are never edited
            // in place, the audit event keeps the before/after).
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['payment_type', 'payment_id', 'status'], 'tld_pay_status_idx');
            $table->index(['created_by', 'payment_type'], 'tld_company_type_idx');
            $table->index(['cost_centre_id'], 'tld_centre_idx');
            $table->index(['bill_id'], 'tld_bill_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_line_dimensions');
    }
};
