<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 5 — petty-cash link on the Expense model (plan §5).
 *
 * A petty-cash expense is an ordinary Expense row (PENDING →
 * APPROVED → EXECUTED, through the existing Expense model) with the
 * fund and the cost centre written on the line — no second table, no
 * second expense flow. The returned amount tracks unspent money
 * handed back to the fund, so an expense is never counted twice.
 *
 * Both columns are nullable: ordinary (non-petty) expenses are
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->bigInteger('treasury_petty_cash_fund_id')->nullable()->after('payment_account_id');
            $table->bigInteger('cost_centre_id')->nullable()->after('treasury_petty_cash_fund_id');
            $table->decimal('treasury_petty_cash_returned_amount', 20, 4)->default(0)->after('cost_centre_id');

            $table->index(['treasury_petty_cash_fund_id'], 'exp_petty_fund_idx');
            $table->index(['cost_centre_id'], 'exp_cost_centre_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('exp_petty_fund_idx');
            $table->dropIndex('exp_cost_centre_idx');
            $table->dropColumn(['treasury_petty_cash_fund_id', 'cost_centre_id', 'treasury_petty_cash_returned_amount']);
        });
    }
};
