<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — fund reservations (plan §5, §6, risk 3).
 *
 * Created when a payment is approved (if reservations are configured).
 * A reservation is NOT a posting — it only reduces the available
 * balance: available = posted − active reservations. `amount` is the
 * reserved amount in the company BASE currency (that is what the
 * Phase 0 available-balance computation subtracts); `amount_original`
 * + `currency` keep the request's own figures.
 *
 * Consumed on execution, released on failure / cancellation /
 * rejection. A reservation is never edited in place for a new
 * amount — a new reservation is created instead.
 *
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_fund_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('payment_request_id');
            // Base-currency amount reserved.
            $table->decimal('amount', 20, 4)->default(0);
            // The request's own amount + currency, for reference.
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->timestamp('expires_at')->nullable();
            $table->decimal('consumed_amount', 20, 4)->default(0);
            $table->decimal('released_amount', 20, 4)->default(0);
            // active / consumed / released / expired / cancelled
            $table->string('state', 20)->default('active');
            $table->text('reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['financial_account_id'], 'tfr_account_idx');
            $table->index(['payment_request_id'], 'tfr_request_idx');
            $table->index(['financial_account_id', 'state'], 'tfr_account_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_fund_reservations');
    }
};
