<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delegated budget approvers (Cost Centre Phase 5, plan §9,
        // spec §9, decision D3).
        //
        // A manager or finance controller can delegate their approval
        // step to another user of the same company for a validity
        // window. Delegation ADDS an approver — the delegator can still
        // decide themselves. The delegate can never be the requester of
        // the request they decide (the no-self-approval rule is checked
        // at decision time, not here, because the requester is only
        // known when a request exists).
        Schema::create('cost_centre_budget_delegations', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('delegator_user_id');
            $table->integer('delegate_user_id');
            // manager | controller — which approval step is delegated.
            $table->string('role', 20);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'delegator_user_id', 'role'], 'ccbd_delegator_idx');
            $table->index(['delegate_user_id'], 'ccbd_delegate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centre_budget_delegations');
    }
};
