<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Budget commitments (Cost Centre Phase 5, plan §9, spec §9).
        //
        // A commitment reserves budget at the purchase-request / PO
        // stage: committed goes up, available goes down. When the
        // invoice arrives the commitment is released (or converted) and
        // the actual goes up through the dimensioned GL actuals — the
        // same money is never counted twice (committed + actual never
        // double-counts one document).
        //
        // The source is generic (source_type + source_id) on purpose:
        // today a commitment comes from a Purchase (purchase order) or
        // any equivalent reservation; the Purchase-flow wiring itself
        // belongs to the v3 Purchasing integration (plan Phase 7), not
        // to this phase. The service API is the contract.
        //
        // A commitment in status pending_approval reserves nothing
        // until its approval request is approved; only active
        // commitments count toward the committed figure.
        Schema::create('cost_centre_commitments', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            // Month of the budget year (1–12).
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            // Optional GL account / project scope, same meaning as on
            // cost_centre_budgets.
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            // purchase | bill | expense | manual — what reserved it.
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            // active | pending_approval | released | converted | cancelled.
            $table->string('status', 20)->default('active');
            // The bill the commitment was converted into (converted
            // status only).
            $table->unsignedBigInteger('converted_bill_id')->nullable();
            // The approval request that gates this commitment
            // (pending_approval status only).
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->integer('requested_by')->nullable();
            $table->integer('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'source_type', 'source_id', 'cost_centre_id', 'scope_key'], 'ccc_source_unique');
            $table->index(['created_by', 'fiscal_year', 'period', 'status'], 'ccc_company_period_idx');
            $table->index(['cost_centre_id'], 'ccc_centre_idx');
            $table->index(['approval_id'], 'ccc_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centre_commitments');
    }
};
