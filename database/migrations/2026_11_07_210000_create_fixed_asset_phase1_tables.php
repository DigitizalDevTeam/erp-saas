<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 1 — Register + Acquisition + Capitalization
 * (plan §5, §10; spec §6, §7, §8, §9).
 *
 * Five tables only: acquisitions (one row per acquisition event,
 * carrying the review → approval → capitalization workflow),
 * acquisition costs (one row per cost component, each with its
 * source-document reference — spec §8), capitalizations (the
 * capitalization event + linked journal), depreciation schedules
 * (the per-period plan the Phase 3 engine posts against) and
 * accounting entries (the audit bridge asset transaction →
 * journal, plan §5).
 *
 * Money is DECIMAL(20,4), rates DECIMAL(20,10) — never float.
 * All index/unique names are explicit and ≤ 23 chars (MySQL's
 * 64-char identifier limit; SQLite tests do not catch this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            // purchase_product | bill_product | delivery_note_item |
            // direct | donation | opening_balance
            $table->string('source_type', 30)->default('direct');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->string('supplier', 255)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('receipt_date')->nullable();
            // D2 (plan §12b): currency + rate + base value.
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            // Workflow (§9): draft → pending_review →
            // pending_approval → approved → capitalized; returned /
            // rejected / cancelled.
            $table->string('status', 30)->default('draft');
            $table->integer('requested_by')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('capitalized_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'facq_asset_idx');
            $table->index(['created_by', 'status'], 'facq_creator_status_idx');
            $table->index(['created_by', 'source_type'], 'facq_creator_src_idx');
        });

        Schema::create('fixed_asset_acquisition_costs', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('acquisition_id');
            // purchase_price | transport | installation |
            // import_duties | non_recoverable_taxes |
            // professional_fees | directly_attributable | discount
            $table->string('component_type', 30)->default('purchase_price');
            // Gross amount in the acquisition currency, VAT included
            // where the source document charged it.
            $table->decimal('gross_amount', 20, 4)->default(0);
            // VAT charged inside gross_amount. Recoverable VAT must
            // NOT form part of capitalized cost (spec §8) — it is
            // subtracted only when vat_recoverable is true.
            $table->decimal('vat_amount', 20, 4)->default(0);
            $table->boolean('vat_recoverable')->default(false);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            // Base-currency contribution of this component (signed:
            // discounts are negative).
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('source_document_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['acquisition_id'], 'facc_acq_idx');
            $table->index(['created_by'], 'facc_creator_idx');
        });

        Schema::create('fixed_asset_capitalizations', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('acquisition_id');
            $table->date('capitalization_date');
            $table->decimal('capitalized_cost', 20, 4)->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->integer('capitalized_by')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'fcap_asset_idx');
            $table->index(['acquisition_id'], 'fcap_acq_idx');
        });

        Schema::create('fixed_asset_depreciation_schedules', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            // First day of the period month.
            $table->date('period');
            $table->unsignedInteger('period_number');
            $table->decimal('opening_book_value', 20, 4)->default(0);
            $table->decimal('period_depreciation', 20, 4)->default(0);
            $table->decimal('accumulated_depreciation', 20, 4)->default(0);
            $table->decimal('closing_book_value', 20, 4)->default(0);
            $table->unsignedInteger('remaining_life_months')->default(0);
            // planned | posted (Phase 3 posts entries against these
            // rows; the schedule itself is only the plan).
            $table->string('status', 20)->default('planned');
            $table->timestamps();

            $table->unique(['created_by', 'asset_id', 'period'], 'fads_asset_period_uq');
            $table->index(['asset_id'], 'fads_asset_idx');
        });

        Schema::create('fixed_asset_accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            // capitalization | depreciation | disposal | ...
            $table->string('transaction_type', 30);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('journal_id');
            $table->timestamps();

            $table->index(['asset_id'], 'faac_asset_idx');
            $table->index(['journal_id'], 'faac_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_accounting_entries');
        Schema::dropIfExists('fixed_asset_depreciation_schedules');
        Schema::dropIfExists('fixed_asset_capitalizations');
        Schema::dropIfExists('fixed_asset_acquisition_costs');
        Schema::dropIfExists('fixed_asset_acquisitions');
    }
};
