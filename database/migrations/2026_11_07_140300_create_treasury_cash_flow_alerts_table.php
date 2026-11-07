<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — cash-flow alerts (plan §8, §10).
 *
 * Alert records, recomputed by the forecast-refresh job (and
 * the cash-flow screen): upcoming / overdue recurring
 * occurrences, insufficient funds (forecast closing balance
 * below the company threshold), pending approvals, delayed
 * transfers, overdue advances, sync failures.
 *
 * One active alert per (company, type, source) — the refresh
 * upserts by that key, so a job run twice never duplicates an
 * alert, and resolves the alert when its condition clears. No
 * email is sent in this phase; the records + screen are the
 * deliverable.
 *
 * All index names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_cash_flow_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            // upcoming_occurrence | overdue_occurrence |
            // insufficient_funds | pending_approval |
            // delayed_transfer | overdue_advance | sync_failure
            $table->string('type', 30);
            // info | warning | critical
            $table->string('severity', 20)->default('warning');
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->bigInteger('source_id')->nullable();
            // active | acknowledged | resolved
            $table->string('state', 20)->default('active');
            $table->timestamp('occurred_at')->nullable();
            $table->bigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'state'], 'tcfa_company_state_idx');
            $table->index(['company_id', 'type'], 'tcfa_company_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_cash_flow_alerts');
    }
};
