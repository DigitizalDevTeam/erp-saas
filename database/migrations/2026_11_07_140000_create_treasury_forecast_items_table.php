<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — cash-flow forecast items (plan §5, §8, §10,
 * CA13).
 *
 * One row per expected movement in the forecast: an opening
 * balance per account, an open invoice, an unpaid payslip, or a
 * manual projection (including contract estimates, which are
 * entered by hand and labelled as estimates until the Contract
 * Management module lands — plan risk 4).
 *
 * System items (balance / invoice / payroll) are regenerated on
 * every forecast refresh — they are projections of live data, so
 * a stale row must never survive. Manual and contract items
 * persist across refreshes; only the refresh touches them, to
 * mark a contract projection replaced when its invoice is issued
 * (replacement_reference) — the invoice item then carries only
 * the outstanding amount, so nothing is ever double-counted
 * (CA13).
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). All
 * index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_forecast_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            // balance | invoice | contract | payroll | expense | manual
            $table->string('source_type', 20);
            $table->bigInteger('source_id')->nullable();
            // inflow | outflow
            $table->string('direction', 10);
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('expected_date');
            // baseline | late_receipts | higher_expenses | earlier_payments
            $table->string('scenario', 20)->default('baseline');
            $table->decimal('probability', 5, 2)->nullable();
            // Contract projections: the invoice reference this
            // projection expects (e.g. the contract number). When an
            // open invoice carries that reference, the projection is
            // replaced — see replacement_reference below.
            $table->string('document', 150)->nullable();
            // Set when the projection was replaced by its issued
            // invoice; replaced items are excluded from the forecast
            // totals (the invoice item carries the outstanding).
            $table->string('replacement_reference', 60)->nullable();
            $table->string('description', 200)->nullable();
            // forecast | actual — actuals are read from confirmed
            // treasury operations in the calendar; this state is kept
            // for items a later phase marks realised.
            $table->string('state', 20)->default('forecast');
            $table->timestamps();

            $table->index(['company_id', 'expected_date'], 'tfi_company_date_idx');
            $table->index(['company_id', 'source_type', 'source_id'], 'tfi_company_src_idx');
            $table->index(['company_id', 'state'], 'tfi_company_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_forecast_items');
    }
};
