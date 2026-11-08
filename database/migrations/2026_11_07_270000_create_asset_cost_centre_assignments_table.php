<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Centre Phase 2 — Fixed Assets integration (plan §5).
 *
 * Effective-dated split assignments of a fixed asset across one
 * or more cost centres: an asset can move centre mid-life, the
 * history is kept, and the depreciation engine splits the period
 * by the assignments in force for that period (plan §7, D4:
 * split by days where an assignment starts or ends mid-period).
 *
 * Legal ownership, the GL asset account and the physical location
 * stay on the asset itself, never on this table. Posted splits
 * are never edited in place: a new assignment supersedes the
 * open generation (old rows keep their valid_to), and ending an
 * assignment closes its rows (valid_to) — the history is the audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_cost_centre_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->integer('company_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->decimal('percentage', 9, 6);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->index(['asset_id'], 'acca_asset_idx');
            $table->index(['created_by'], 'acca_creator_idx');
            $table->index(['cost_centre_id'], 'acca_centre_idx');
            $table->index(['asset_id', 'valid_from'], 'acca_asset_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_cost_centre_assignments');
    }
};
