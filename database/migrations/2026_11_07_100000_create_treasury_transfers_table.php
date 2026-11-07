<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 4 — internal transfers with FX (plan §5, §6, §10).
 *
 * One row per transfer, linked to the one unified treasury operation
 * (type transfer). The operation carries the reference ({PREFIX}-
 * TRF-{YEAR}-{SEQ}, allocated only when the transfer leaves Draft)
 * and the base amounts; this row carries the FX detail.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). The
 * exchange rate converts source → destination currency; the base
 * rate converts source → company base currency. The destination →
 * base rate is derived (base ÷ exchange), never entered twice, so
 * the two legs always reconcile and any residual lands on the
 * exchange-difference account as an explicit line (CA19).
 *
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->bigInteger('destination_account_id');
            $table->bigInteger('transit_account_id')->nullable();
            // Currencies are taken from the accounts, never typed
            // by hand — an account's currency cannot change once
            // it has a confirmed movement.
            $table->string('source_currency', 3)->default('MZN');
            $table->string('destination_currency', 3)->default('MZN');
            // immediate / delayed
            $table->string('timing', 20)->default('immediate');
            // Amount debited from the source, in source currency.
            $table->decimal('amount_debited', 20, 4)->default(0);
            // Source → destination rate (1 when same currency).
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            // Source → base rate (1 when the source is the base
            // currency; equal to exchange_rate when the destination
            // is the base currency; entered when both are foreign).
            $table->decimal('base_exchange_rate', 20, 10)->nullable();
            // Amount credited to the destination, in destination
            // currency (debited × exchange_rate).
            $table->decimal('amount_credited', 20, 4)->default(0);
            // Both legs in base currency.
            $table->decimal('amount_debited_base', 20, 4)->default(0);
            $table->decimal('amount_credited_base', 20, 4)->default(0);
            // Fee charged on top of the principal, in source
            // currency, posted as its own expense line.
            $table->decimal('fee_amount', 20, 4)->default(0);
            $table->decimal('fee_base', 20, 4)->default(0);
            $table->bigInteger('fee_account_id')->nullable();
            // Signed base difference: debited_base − credited_base.
            // Positive is a realised loss, negative a realised gain
            // (rounding only, when the cross rate is consistent).
            $table->decimal('exchange_difference_base', 20, 4)->default(0);
            $table->bigInteger('exchange_difference_account_id')->nullable();
            // draft / in_transit / confirmed / returned / cancelled
            $table->string('state', 20)->default('draft');
            $table->text('evidence')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->unique(['operation_id'], 'tt_operation_uq');
            $table->index(['created_by', 'state'], 'tt_company_state_idx');
            $table->index(['source_account_id'], 'tt_source_idx');
            $table->index(['destination_account_id'], 'tt_dest_idx');
            $table->index(['transit_account_id'], 'tt_transit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_transfers');
    }
};
