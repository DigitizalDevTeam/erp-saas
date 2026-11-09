<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_buffet_event_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_buffet_event_id');
            $table->unsignedBigInteger('product_id');
            // The approved recipe the preparation was produced from
            // (null for a direct stock item served as-is).
            $table->unsignedBigInteger('kitchen_recipe_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            // Quantity per cover (when the plan was made from covers)
            // — planned = per-cover x expected covers.
            $table->decimal('per_cover_quantity', 12, 4)->nullable();
            // Planned quantity for the event (stock units).
            $table->decimal('planned_quantity', 12, 4);
            // Reconciliation after service: prepared = used + leftover
            // + waste. Filled at prepare (prepared) and at close (the
            // other three).
            $table->decimal('prepared_quantity', 12, 4)->default(0);
            $table->decimal('used_quantity', 12, 4)->default(0);
            $table->decimal('leftover_quantity', 12, 4)->default(0);
            $table->decimal('waste_quantity', 12, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['kitchen_buffet_event_id', 'product_id'], 'kbel_event_product_uq');
            $table->index(['kitchen_buffet_event_id'], 'kbel_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_buffet_event_lines');
    }
};
