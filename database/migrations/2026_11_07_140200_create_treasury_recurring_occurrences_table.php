<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — recurring commitment occurrences (plan §5,
 * §6, §10, CA14).
 *
 * One row per dated occurrence of a rule. period_key is unique
 * per rule + period, so a job run twice creates one occurrence
 * per rule and period (CA14) — the unique index is the second
 * guard after the service's firstOrCreate.
 *
 * Money is DECIMAL(20,4) (RF04). All index names are explicit
 * and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_recurring_occurrences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('rule_id');
            $table->string('period_key', 40);
            $table->date('scheduled_date');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            // inflow | outflow — copied from the rule.
            $table->string('direction', 10)->default('outflow');
            // generated | confirmed | paid | skipped
            $table->string('state', 20)->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['rule_id', 'period_key'], 'tro_rule_period_uq');
            $table->index(['rule_id', 'scheduled_date'], 'tro_rule_date_idx');
            $table->index(['created_by', 'state'], 'tro_creator_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_recurring_occurrences');
    }
};
