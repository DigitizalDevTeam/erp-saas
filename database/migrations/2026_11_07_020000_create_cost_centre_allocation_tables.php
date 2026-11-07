<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One allocation header per source document LINE (plan §5): a bill
        // with three lines can have three different splits.
        Schema::create('cost_centre_allocations', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            // bill | expense (Phase 1). journal | payment | asset follow later.
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            // bill_product | bill_account — which row of the source document.
            $table->string('source_line_type', 20);
            $table->unsignedBigInteger('source_line_id');
            // percentage | fixed_amount
            $table->string('basis', 20)->default('percentage');
            // The line amount the split was computed against (2dp). The
            // split is validated against this, never against a client value.
            $table->decimal('source_amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            // draft | posted | reversed — posted splits are never edited.
            $table->string('status', 20)->default('draft');
            // Snapshot of the rule that produced the posted amounts
            // (basis, input rows, residual line, tolerance, engine version).
            $table->json('rule_snapshot')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            // Reverse-and-repost traceability: the allocation this one
            // replaces, and the reversal record on this one.
            $table->unsignedBigInteger('replaces_allocation_id')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'source_type', 'source_id'], 'cca_src_idx');
            $table->index(['created_by', 'source_type', 'source_id', 'source_line_type', 'source_line_id', 'status'], 'cca_line_status_idx');
            $table->index(['created_by', 'status'], 'cca_company_status_idx');
            $table->index(['template_id'], 'cca_template_idx');
        });

        Schema::create('cost_centre_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('cost_centre_id');
            // DECIMAL(9,6): splits sum to exactly 100.000000%.
            $table->decimal('percentage', 9, 6)->nullable();
            $table->decimal('fixed_amount', 18, 2)->nullable();
            // The actual rounded amount posted — persisted, never recomputed.
            $table->decimal('allocated_amount', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['allocation_id', 'sort_order'], 'ccal_alloc_sort_idx');
            $table->index(['cost_centre_id'], 'ccal_centre_idx');
            $table->foreign('allocation_id', 'ccal_alloc_fk')
                ->references('id')->on('cost_centre_allocations')
                ->onDelete('cascade');
        });

        // Named templates per company (plan §5): a starting point only —
        // the posted split is what counts.
        Schema::create('cost_centre_allocation_templates', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('name', 100);
            $table->string('basis', 20)->default('percentage');
            // [{cost_centre_id, percentage, fixed_amount, sort_order}]
            $table->json('lines');
            $table->timestamps();

            $table->unique(['created_by', 'name'], 'ccat_company_name_uq');
            $table->index(['created_by'], 'ccat_company_idx');
        });

        // Append-only audit trail (plan §5): every allocation, reversal
        // and approval writes one row. No updates, no deletes.
        Schema::create('dimension_audit_events', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            // created | posted | reversed | approved | deleted | rebased
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['company_id', 'entity_type', 'entity_id'], 'dae_entity_idx');
            $table->index(['company_id', 'occurred_at'], 'dae_company_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dimension_audit_events');
        Schema::dropIfExists('cost_centre_allocation_templates');
        Schema::dropIfExists('cost_centre_allocation_lines');
        Schema::dropIfExists('cost_centre_allocations');
    }
};
