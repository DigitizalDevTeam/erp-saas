<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Budget approval requests (Cost Centre Phase 5, plan §9,
        // spec §9, decision D3).
        //
        // One row per over-budget request. The chain is requester →
        // cost centre manager → finance controller, with time-stamped
        // decisions at every step. The manager and controller user ids
        // are snapshotted when the request is created; delegated
        // approvers are resolved at decision time from
        // cost_centre_budget_delegations, so a delegation set after the
        // request was raised still counts.
        //
        // requires_manager / requires_controller are computed at
        // creation: a step is not required when its approver is the
        // requester (no self-approval, ever) or — for the controller —
        // when the amount is at or below the company's approval
        // threshold (small amounts skip the controller step, D3). A
        // request whose requester holds every approval role is
        // auto-approved at creation and the reason is recorded.
        Schema::create('cost_centre_budget_approvals', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('commitment_id')->nullable();
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            // The amount that needs approval (the full amount of the
            // expense/commitment, not just the excess — the approver
            // decides on the whole spend).
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->text('reason')->nullable();
            // pending | manager_approved | approved | rejected.
            $table->string('status', 20)->default('pending');
            $table->integer('requested_by');
            // Snapshotted approvers (delegation resolved at decide time).
            $table->integer('manager_user_id')->nullable();
            $table->integer('controller_user_id')->nullable();
            $table->boolean('requires_manager')->default(true);
            $table->boolean('requires_controller')->default(true);
            $table->integer('manager_decided_by')->nullable();
            $table->timestamp('manager_decided_at')->nullable();
            $table->string('manager_decision', 20)->nullable();
            $table->text('manager_note')->nullable();
            $table->integer('controller_decided_by')->nullable();
            $table->timestamp('controller_decided_at')->nullable();
            $table->string('controller_decision', 20)->nullable();
            $table->text('controller_note')->nullable();
            // Why the request ended the way it did when no human
            // decided (e.g. auto-approved: requester holds all roles).
            $table->string('auto_note')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'status'], 'ccba_company_status_idx');
            $table->index(['cost_centre_id'], 'ccba_centre_idx');
            $table->index(['requested_by'], 'ccba_requester_idx');
            $table->index(['commitment_id'], 'ccba_commitment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centre_budget_approvals');
    }
};
