<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 1 — settlement allocations (plan §5).
 *
 * One receipt settles many invoices; many receipts settle one
 * invoice. Total applied can never exceed the outstanding debt or
 * the available (unapplied) credit. Excess stays as unapplied
 * customer credit; applying it later creates no new cash inflow
 * (source = 'unapplied_credit' allocations post only the
 * credit-liability -> AR journal, never a bank movement).
 *
 * Cross-currency allocations store their own rate + source + date
 * and the approver who authorised them (approved_by); without both
 * a cross-currency allocation is blocked. A reversal marks the
 * allocation 'reversed' and reopens the debt (CA15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_settlement_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('receipt_id')->nullable();
            // Phase 3 payments link here; null in Phase 1.
            $table->bigInteger('payment_id')->nullable();
            // invoice / bill (Phase 1: invoice only)
            $table->string('target_type', 20)->default('invoice');
            $table->bigInteger('target_id');
            // In the receipt currency.
            $table->decimal('allocated_amount', 20, 4)->default(0);
            // In the company base currency (what the invoice is settled by).
            $table->decimal('allocated_amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            // active / reversed
            $table->string('state', 20)->default('active');
            // receipt / unapplied_credit
            $table->string('source', 20)->default('receipt');
            // Cross-currency approval (a different user than the creator).
            $table->bigInteger('approved_by')->nullable();
            // The InvoicePayment row this allocation settled with.
            $table->bigInteger('invoice_payment_id')->nullable();
            // The JournalEntry the allocation journal posted.
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['receipt_id', 'state'], 'tsa_receipt_state_idx');
            $table->index(['target_type', 'target_id', 'state'], 'tsa_target_state_idx');
            $table->index(['created_by', 'state'], 'tsa_company_state_idx');
            $table->index(['invoice_payment_id'], 'tsa_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_settlement_allocations');
    }
};
