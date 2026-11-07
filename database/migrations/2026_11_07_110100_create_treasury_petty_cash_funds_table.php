<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 5 — petty cash (plan §5, §10).
 *
 * A petty-cash fund is a small cash float held by a custodian on one
 * financial account (a cash register is the usual choice, any active
 * account works). The fund's limit is the target float: the
 * replenishment due is limit − posted balance, floored at zero, so a
 * top-up (a normal Phase 4 transfer into the fund account) brings
 * the fund back to its limit. Replenishment is always the spent
 * amount — executed expenses minus amounts returned unspent.
 *
 * One fund per account (unique) — two funds on the same account
 * would make the balance meaningless.
 *
 * Money is DECIMAL(20,4) (RF04). All index names are explicit and
 * short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_petty_cash_funds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('custodian_user_id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('branch_id')->nullable();
            // Target float. NULL means "no target" — nothing is due.
            $table->decimal('limit_amount', 20, 4)->nullable();
            // weekly / monthly / on_demand
            $table->string('replenishment_frequency', 20)->default('on_demand');
            // active / inactive
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['financial_account_id'], 'tpcf_account_uq');
            $table->index(['created_by', 'status'], 'tpcf_company_idx');
            $table->index(['custodian_user_id'], 'tpcf_custodian_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_petty_cash_funds');
    }
};
