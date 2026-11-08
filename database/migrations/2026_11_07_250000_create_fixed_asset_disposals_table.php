<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 5 — Disposals (plan §5, §10; spec §16, §17).
 *
 * One row per disposal request. The workflow is request →
 * approval → execution; the execution posts the disposal
 * journal (cost + accumulated depreciation removed, gain/loss
 * on actual proceeds) and flips the asset to disposed (or
 * written_off for a loss write-off). The asset is never
 * deleted — it stays in the register with full history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            // sale | scrapping | donation | write_off
            $table->string('method', 20);
            // requested | approved | rejected | executed | cancelled
            $table->string('status', 20)->default('requested');
            $table->text('reason')->nullable();
            // Expected proceeds at request time (0 for scrapping /
            // donation / write-off). The gain/loss follows the
            // ACTUAL proceeds recorded at execution, not this.
            $table->decimal('expected_proceeds', 20, 4)->default(0);
            $table->decimal('actual_proceeds', 20, 4)->nullable();
            // Sale proceeds received in cash/bank now (Dr 12/11);
            // false means sold on credit (Dr 411).
            $table->boolean('proceeds_received')->default(true);
            $table->string('buyer_name', 255)->nullable();
            $table->date('disposal_date')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            // Signed: + gain, − loss (actual proceeds − book value
            // at disposal date). Null until executed.
            $table->decimal('gain_loss_amount', 20, 4)->nullable();
            $table->decimal('book_value_at_disposal', 20, 4)->nullable();
            $table->decimal('accumulated_depreciation_at_disposal', 20, 4)->nullable();
            // Asset status before the request, so a rejection or a
            // cancellation can put the asset back where it was.
            $table->string('previous_status', 30)->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('executed_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id'], 'fad_asset_idx');
            $table->index(['created_by'], 'fad_creator_idx');
            $table->index(['created_by', 'status'], 'fad_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
