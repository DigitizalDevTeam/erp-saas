<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 4 — Maintenance + Verification (plan §5, §10;
 * spec §15, §19).
 *
 * Four tables only:
 * - fixed_asset_maintenance_requests: one row per maintenance
 *   request. Workflow: requested → approved → in_progress →
 *   completed → verified; rejected / cancelled are terminal.
 *   On approval the asset moves to under_maintenance; on
 *   completion it returns to its previous_status (stored on
 *   the row). Assignment, cost centre and depreciation schedule
 *   are untouched by maintenance.
 * - fixed_asset_maintenance_costs: one row per actual cost
 *   (parts / labour / other) recorded against a request. Each
 *   cost posts its own journal (Dr maintenance expense /
 *   Cr accounts payable) through JournalEntryPoster only, at
 *   recording time. A cost is an expense — it is never added
 *   to the asset's capitalized cost.
 * - fixed_asset_physical_verifications: one row per stocktake
 *   campaign (one location, or the whole company when
 *   location_id is null). Workflow: planned → in_progress →
 *   closed; cancelled is terminal. Assets move to
 *   under_verification while the campaign runs.
 * - fixed_asset_verification_items: one row per expected asset
 *   in a campaign (snapshotted at creation, with the asset's
 *   status at that time in previous_status). Result: found /
 *   missing / damaged / wrong_location / wrong_custodian.
 *   Missing assets are flagged, never automatically written
 *   off — on close a missing asset becomes "lost" (still on
 *   the books, still depreciating) and a damaged one becomes
 *   "damaged"; everything else returns to its previous status.
 *
 * All index/unique names are explicit and ≤ 23 chars (MySQL's
 * 64-char identifier limit; SQLite tests do not catch this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 20)->default('corrective');
            $table->string('priority', 20)->default('medium');
            $table->text('description');
            $table->decimal('expected_cost', 20, 4)->nullable();
            // Workflow (§15): requested → approved → in_progress →
            // completed → verified; rejected / cancelled terminal.
            $table->string('status', 30)->default('requested');
            // Asset status before maintenance (restored on
            // completion / cancel-after-approval).
            $table->string('previous_status', 30)->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('started_by')->nullable();
            $table->integer('completed_by')->nullable();
            $table->integer('verified_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['asset_id'], 'famr_asset_idx');
            $table->index(['created_by', 'status'], 'famr_creator_status_idx');
            $table->index(['requested_by'], 'famr_requested_idx');
        });

        Schema::create('fixed_asset_maintenance_costs', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('maintenance_request_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('cost_type', 20)->default('parts');
            $table->text('description')->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('vendor', 255)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->integer('recorded_by');
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->index(['maintenance_request_id'], 'famc_request_idx');
            $table->index(['asset_id'], 'famc_asset_idx');
        });

        Schema::create('fixed_asset_physical_verifications', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            // Null = whole company; set = one location only.
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('name', 255);
            $table->date('deadline');
            // Workflow (§19): planned → in_progress → closed;
            // cancelled is terminal.
            $table->string('status', 30)->default('planned');
            $table->integer('created_by_user')->nullable();
            $table->integer('started_by')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'status'], 'fapv_creator_status_idx');
            $table->index(['location_id'], 'fapv_location_idx');
        });

        Schema::create('fixed_asset_verification_items', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('verification_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('expected_location_id')->nullable();
            $table->unsignedBigInteger('expected_custodian_id')->nullable();
            // Asset status when the campaign was created — the
            // status the asset returns to on close (unless the
            // result overrides it: missing → lost, damaged →
            // damaged).
            $table->string('previous_status', 30)->nullable();
            // pending / found / missing / damaged /
            // wrong_location / wrong_custodian.
            $table->string('result', 30)->default('pending');
            $table->integer('checked_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['verification_id'], 'favi_verification_idx');
            $table->index(['asset_id'], 'favi_asset_idx');
            $table->unique(['verification_id', 'asset_id'], 'favi_verif_asset_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_verification_items');
        Schema::dropIfExists('fixed_asset_physical_verifications');
        Schema::dropIfExists('fixed_asset_maintenance_costs');
        Schema::dropIfExists('fixed_asset_maintenance_requests');
    }
};
