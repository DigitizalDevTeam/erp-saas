<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 1 — billing contracts master (plan §5, §10).
 *
 * The contract master: customer, term, billing schedule (frequency,
 * anchor day, advance/arrears), payment terms, approval mode,
 * proration choice, lifecycle state, delivery settings. Contract
 * numbers are per company, sequential, never reused, allocated at
 * creation (CNT-2026-0001).
 *
 * Money is DECIMAL(20,4); rates DECIMAL(20,10). All index names
 * are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('branch_id')->nullable();
            $table->bigInteger('customer_id');
            $table->string('reference', 30);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('currency', 3)->default('MZN');
            // Contract currency -> base (MZN) rate, snapshotted at
            // creation; 1 when the contract is in the base currency.
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            // monthly | quarterly | semi-annual | annual | custom
            $table->string('billing_frequency', 20)->default('monthly');
            $table->integer('custom_interval')->nullable();
            // days | weeks | months | years (custom frequency only)
            $table->string('custom_unit', 10)->nullable();
            // Stored anchor day (1-31), never recomputed from the
            // last issue date (plan §11 risk 7).
            $table->integer('anchor_day');
            // advance | arrears
            $table->string('billing_mode', 10)->default('advance');
            $table->integer('payment_terms_days')->default(15);
            // approval | automatic (D2: approval is the default)
            $table->string('approval_mode', 10)->default('approval');
            $table->boolean('proration_enabled')->default(false);
            // full | prorate | skip — the activation choice for a
            // partial first/final period (D3); 'prorate' implies
            // proration_enabled, and vice versa.
            $table->string('partial_period_rule', 10)->default('full');
            // draft | pending_approval | active | suspended | ended | cancelled
            $table->string('state', 20)->default('draft');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('renewal_notice_days')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->boolean('delivery_auto_email')->default(false);
            $table->text('delivery_to')->nullable();
            $table->text('delivery_cc')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('current_revision_id')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'reference'], 'bc_company_ref_uq');
            $table->index(['created_by', 'state'], 'bc_company_state_idx');
            $table->index(['customer_id'], 'bc_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_contracts');
    }
};
