<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixed Assets Phase 3 — depreciation runs (plan §5, §10;
     * spec §13, §14).
     *
     * The monthly run: CALCULATED → REVIEWED → APPROVED → POSTED.
     * One run per company + period; one entry per asset per
     * period. The unique (company + asset + period) key on the
     * entries is the duplicate-period guard — a period can never
     * post twice, even if a second run is attempted. The runs
     * table itself has no unique key on (company + period) on
     * purpose: a cancelled run must not block a replacement run
     * for the same period (cancel deletes its unposted entries).
     *
     * All index/unique names are explicit and ≤ 23 chars (MySQL's
     * 64-char identifier limit).
     */
    public function up(): void
    {
        Schema::create('fixed_asset_depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            // First day of the period month (e.g. 2026-11-01).
            $table->date('period');
            // calculated | reviewed | approved | posted | cancelled
            $table->string('status', 20)->default('calculated');
            $table->decimal('total_amount', 20, 4)->default('0');
            $table->integer('asset_count')->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->integer('requested_by')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->integer('posted_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Deliberately a plain index, NOT a unique key: a
            // cancelled run must not block a replacement run for
            // the same period. "One live run per period" is enforced
            // in the service (createRun refuses when a non-cancelled
            // run exists); the entries' unique key is the hard
            // backstop that stops two runs ever posting the same
            // asset + period.
            $table->index(['created_by', 'period'], 'fadr_creator_period_idx');
            $table->index(['created_by', 'status'], 'fadr_creator_status_idx');
        });

        Schema::create('fixed_asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('schedule_id');
            $table->date('period');
            // Planned amount from the Phase 1 schedule shell (4dp).
            $table->decimal('planned_amount', 20, 4);
            // Actually posted amount (2dp, after the poster's rounding;
            // the final period absorbs the rounding so the posted total
            // is exact). Null until posted.
            $table->decimal('posted_amount', 20, 4)->nullable();
            // Cost centre the amount was allocated to (effective for
            // the period; null when the asset has none).
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            // calculated | posted
            $table->string('status', 20)->default('calculated');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();

            // Duplicate-period guard: one posted entry per asset per
            // period, for the whole company, whatever the run.
            $table->unique(['created_by', 'asset_id', 'period'], 'fade_asset_period_uq');
            $table->index(['run_id'], 'fade_run_idx');
            $table->index(['created_by', 'status'], 'fade_creator_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciation_entries');
        Schema::dropIfExists('fixed_asset_depreciation_runs');
    }
};
