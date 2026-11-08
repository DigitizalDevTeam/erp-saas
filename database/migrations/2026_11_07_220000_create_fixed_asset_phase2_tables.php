<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 2 — Assignment + Transfer (plan §5, §10;
 * spec §11, §12).
 *
 * Two tables only:
 * - fixed_asset_assignments: one row per assignment event. The
 *   row keeps the whole lifecycle (requested → approved →
 *   handed over → accepted / refused / returned / cancelled),
 *   so history is never overwritten — a new assignment is a new
 *   row. The asset is "assigned" only when the custodian
 *   accepts; a refusal returns it to available, recorded.
 * - fixed_asset_transfers: one row per transfer event
 *   (request → approval → handover → receipt). The previous
 *   location / custodian / cost centre are stored on the row,
 *   and the asset master is updated only on receipt. A transfer
 *   never touches the depreciation schedule and posts no
 *   journal (plan §7 has no transfer posting).
 *
 * Cost centre (plan §12c): a transfer/assignment carries a
 * single effective cost centre (the asset's cost_centre_id).
 * Historical assignment is preserved on these rows; there are
 * no overlapping assignments — one active assignment/transfer
 * per asset, enforced in the service layer. Multi-way splits
 * are a Cost Centre module concern, not Phase 2.
 *
 * All index/unique names are explicit and ≤ 23 chars (MySQL's
 * 64-char identifier limit; SQLite tests do not catch this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('custodian_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            // Where the asset was before this assignment (null on
            // the first assignment of a never-assigned asset).
            $table->unsignedBigInteger('previous_custodian_id')->nullable();
            $table->unsignedBigInteger('previous_location_id')->nullable();
            $table->unsignedBigInteger('previous_cost_centre_id')->nullable();
            // Workflow (§11): requested → approved → handed_over →
            // accepted; refused / returned / cancelled are terminal.
            $table->string('status', 30)->default('requested');
            $table->string('condition', 100)->nullable();
            $table->text('accessories')->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('handed_over_by')->nullable();
            $table->integer('accepted_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            // Effective-dated cost-centre assignment (plan §12c):
            // from acceptance until return.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'fass_asset_idx');
            $table->index(['created_by', 'status'], 'fass_creator_status_idx');
            $table->index(['custodian_id'], 'fass_custodian_idx');
        });

        Schema::create('fixed_asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->unsignedBigInteger('from_custodian_id')->nullable();
            $table->unsignedBigInteger('to_custodian_id')->nullable();
            $table->unsignedBigInteger('from_cost_centre_id')->nullable();
            $table->unsignedBigInteger('to_cost_centre_id')->nullable();
            // Workflow (§12): requested → approved → handed_over →
            // received; rejected / cancelled are terminal.
            $table->string('status', 30)->default('requested');
            // Asset status before the request, so a rejected or
            // cancelled transfer can put the asset back exactly
            // where it was (assigned / available).
            $table->string('previous_status', 30)->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('handed_over_by')->nullable();
            $table->integer('received_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('received_at')->nullable();
            // Effective date of the move (the receipt date).
            $table->date('effective_date')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'fast_asset_idx');
            $table->index(['created_by', 'status'], 'fast_creator_status_idx');
            $table->index(['to_location_id'], 'fast_to_loc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_transfers');
        Schema::dropIfExists('fixed_asset_assignments');
    }
};
