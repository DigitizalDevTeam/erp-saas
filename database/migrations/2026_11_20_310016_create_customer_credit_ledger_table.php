<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit Notes Phase 3 — per-customer credit ledger.
     *
     * One `added` row per credit note is a LOT (oldest-first use,
     * 14-day expiry from when the credit was added). `used` /
     * `expired` / `reversed` rows consume a lot and keep the lot's
     * remaining balance after the event, so the ledger reads as a
     * small checking account: credit added, credit used, credit
     * expired, credit reversed (note deleted). `customers.credit_balance`
     * stays the denormalised total of all lot remainings.
     *
     * NOTE (MySQL 64-char limit): index names are kept short.
     */
    public function up(): void
    {
        if (Schema::hasTable('customer_credit_ledger')) {
            return;
        }

        Schema::create('customer_credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('credit_note_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            // The invoice_payments row that consumed this lot (used rows only).
            $table->unsignedBigInteger('payment_id')->nullable();
            // added / used / expired / reversed
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            // The lot's remaining balance AFTER this event.
            $table->decimal('remaining', 15, 2)->default(0);
            // Set on `added` rows: created_at + 14 days. Null otherwise.
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index('customer_id', 'ccl_customer_idx');
            $table->index('credit_note_id', 'ccl_note_idx');
            $table->index('expires_at', 'ccl_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_ledger');
    }
};
