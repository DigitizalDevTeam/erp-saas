<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — payment requests (plan §5, §6).
 *
 * Workflow: draft → under_review → awaiting_approval → approved →
 * processing → paid / failed / rejected; outcome_unknown; cancelled
 * or reversed. The operation reference is allocated when the request
 * leaves Draft (submit), so a cancelled draft never consumes a
 * number. `client_reference` is the company's own reference and is
 * unique per company — duplicates are blocked.
 *
 * Money is DECIMAL(20,4); rates DECIMAL(20,10) (RF04). All index
 * names are explicit and short (MySQL 64-char limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_payment_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            // vendor / employee / customer / other
            $table->string('beneficiary_type', 20);
            $table->bigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 150)->nullable();
            $table->string('beneficiary_account', 150)->nullable();
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            // amount_original x exchange_rate, in the company base currency.
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->date('planned_date')->nullable();
            // low / normal / high / urgent
            $table->string('priority', 20)->default('normal');
            $table->text('reason')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->bigInteger('project_id')->nullable();
            // [{type: 'bill'|'invoice', id: n}, ...]
            $table->json('source_documents')->nullable();
            // The company's own reference for this payment (optional).
            $table->string('client_reference', 100)->nullable();
            // Similar-payment warnings found at submit (review only).
            $table->json('similar_flags')->nullable();
            // draft / under_review / awaiting_approval / approved /
            // processing / paid / failed / rejected / outcome_unknown /
            // cancelled / reversed
            $table->string('state', 20)->default('draft');
            $table->integer('requested_by');
            $table->integer('executor_user_id')->nullable();
            // Snapshot of the approved data + rule version (CA05).
            $table->json('approved_snapshot')->nullable();
            $table->integer('approved_rule_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'client_reference'], 'tpr_client_ref_uq');
            $table->index(['created_by', 'state'], 'tpr_company_state_idx');
            $table->index(['source_account_id'], 'tpr_account_idx');
            $table->index(['beneficiary_type', 'beneficiary_id'], 'tpr_beneficiary_idx');
            $table->index(['operation_id'], 'tpr_operation_idx');
            $table->index(['requested_by'], 'tpr_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_payment_requests');
    }
};
