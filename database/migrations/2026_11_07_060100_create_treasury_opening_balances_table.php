<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 0 — opening balances (plan §5).
 *
 * Draft → approved. A confirmed (approved) balance is corrected only
 * by an authorised reversal — a new row linked via `reversal_of_id`
 * with negated amounts — never by editing the confirmed row (RF03).
 *
 * Multi-currency (D2): every row stores the original amount, the base
 * amount, the exchange rate, the rate source and the rate date. When
 * currency == base currency the rate is 1 and base == original.
 * `includes_liabilities` / `includes_receivables` flag balances whose
 * liabilities/receivables are already in the migration, so later
 * settlements do not recreate them.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). All index
 * names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_opening_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->integer('created_by');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            // bank_rate / central_bank / manual (D2 — always named).
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->date('cut_off_date');
            $table->string('batch_reference', 100);
            $table->bigInteger('operator_user_id')->nullable();
            $table->text('attachments')->nullable();
            // draft / approved / reversed
            $table->string('status', 20)->default('draft');
            $table->boolean('includes_liabilities')->default(false);
            $table->boolean('includes_receivables')->default(false);
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            // One import batch shares one batch_reference across its
            // rows, so the reference is NOT unique per row. The
            // service refuses to re-use a reference the company has
            // already used (a repeated import must get a new one).
            $table->index(['created_by', 'batch_reference'], 'tob_company_batch_idx');
            $table->index(['financial_account_id', 'status'], 'tob_account_status_idx');
            $table->index(['created_by', 'status'], 'tob_company_status_idx');
            $table->index(['cut_off_date'], 'tob_cutoff_idx');
            $table->index(['reversal_of_id'], 'tob_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_opening_balances');
    }
};
