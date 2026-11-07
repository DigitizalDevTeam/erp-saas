<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_cost_snapshots', function (Blueprint $table) {
            $table->id();
            // The recipe version this snapshot belongs to. Every version
            // is a separate kitchen_recipes row, so kitchen_recipe_id
            // alone identifies the version; the version column is kept
            // for readability in the history timeline.
            $table->unsignedBigInteger('kitchen_recipe_id');
            $table->integer('version')->default(1);
            // The completed order this snapshot belongs to (null for
            // recipe_approved snapshots).
            $table->unsignedBigInteger('kitchen_production_order_id')->nullable();
            // recipe_approved | order_completed (plan §7, risk 6: a
            // snapshot is written at the time, so a later price change
            // never rewrites history).
            $table->string('source', 30);
            // Recipe cost for a whole batch (recipe_approved) or the
            // order's actual cost (order_completed); null for order
            // snapshots that only carry the per-portion figures — kept
            // nullable so both sources fit one table.
            $table->decimal('recipe_cost', 15, 2)->nullable();
            $table->decimal('cost_per_portion', 15, 2)->nullable();
            $table->decimal('selling_price_ex_vat', 15, 2)->nullable();
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('band', 20)->nullable();
            $table->date('snapshot_date');
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['created_by', 'kitchen_recipe_id', 'version'], 'kcs_recipe_version_idx');
            $table->index(['kitchen_production_order_id'], 'kcs_order_idx');
            $table->index(['created_by', 'snapshot_date'], 'kcs_created_by_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_cost_snapshots');
    }
};
