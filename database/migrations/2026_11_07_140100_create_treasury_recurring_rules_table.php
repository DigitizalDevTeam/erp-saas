<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — recurring commitment rules (plan §5, §6,
 * §10, CA14).
 *
 * One rule is one repeating commitment: a monthly rent, a
 * quarterly service fee, an annual licence — or a repeating
 * receipt (direction inflow). The rule holds the schedule; the
 * occurrences hold the dated amounts.
 *
 * Changes apply to future UNCONFIRMED occurrences only; pausing
 * or ending a rule keeps earlier occurrences. Automatic
 * execution is disabled by default — an occurrence never creates
 * a payment by itself (plan §6, §12).
 *
 * Money is DECIMAL(20,4) (RF04). All index names are explicit
 * and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_recurring_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('beneficiary', 150);
            $table->string('description', 250)->nullable();
            $table->string('category', 100)->nullable();
            // inflow | outflow — most rules are commitments to pay.
            $table->string('direction', 10)->default('outflow');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            // fixed | estimated
            $table->string('amount_type', 20)->default('fixed');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // monthly | quarterly | semi-annual | annual | custom
            $table->string('frequency', 20);
            $table->integer('custom_interval')->nullable();
            // days | weeks | months | years (custom only)
            $table->string('custom_unit', 20)->nullable();
            // Anchor day of the month (1-31); defaults to the start
            // date's day. Clamped to the month length: 31 Jan → 28
            // Feb → 31 Mar.
            $table->integer('due_day')->nullable();
            $table->bigInteger('planned_account_id')->nullable();
            $table->boolean('approval_required')->default(false);
            // active | paused | ended
            $table->string('state', 20)->default('active');
            // Bumped on every rule update; occurrences generated
            // under an older version keep their amount.
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index(['company_id', 'state'], 'trr_company_state_idx');
            $table->index(['created_by', 'state'], 'trr_creator_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_recurring_rules');
    }
};
