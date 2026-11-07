<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cost centre budgets (Cost Centre Phase 5, plan §9, spec §9).
        //
        // One budget per company + fiscal year + month + cost centre +
        // scope. The fiscal year is the calendar year (plan D5: the app
        // has no fiscal-year configuration of its own — the year-end
        // close is FY-{year}-CLOSE on Dec 31, so the budget year is the
        // calendar year) and the period is the month (1–12).
        //
        // The scope is the optional GL account and/or project. A budget
        // with no account and no project covers the whole centre; a
        // budget with an account covers only that account. The scope is
        // stored twice — as columns (for queries) and as one scope_key
        // string (for the unique index, because MySQL treats NULLs in a
        // unique index as distinct values and would allow duplicates).
        //
        // budget_amount is the planned figure; approved_amount is the
        // figure that counts once the budget is approved (set to
        // budget_amount on approval). Draft budgets count as zero — only
        // approved budgets limit spending.
        Schema::create('cost_centre_budgets', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            // Month of the budget year (1–12).
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            // Optional GL account (chart_of_accounts id) — null means
            // the whole centre.
            $table->unsignedBigInteger('gl_account_id')->nullable();
            // Optional second dimension — null means all projects (D6:
            // no default project is ever filled in).
            $table->unsignedBigInteger('project_id')->nullable();
            // 'all' | 'gl:{id}' | 'prj:{id}' | 'gl:{id}:prj:{id}'.
            $table->string('scope_key', 60);
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->decimal('approved_amount', 18, 2)->default(0);
            // Base currency of the company (D7).
            $table->string('currency_code', 10)->nullable();
            // draft | approved.
            $table->string('status', 20)->default('draft');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'fiscal_year', 'period', 'cost_centre_id', 'scope_key'], 'ccb_scope_unique');
            $table->index(['created_by', 'fiscal_year', 'period'], 'ccb_company_period_idx');
            $table->index(['cost_centre_id'], 'ccb_centre_idx');
            $table->index(['gl_account_id'], 'ccb_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centre_budgets');
    }
};
