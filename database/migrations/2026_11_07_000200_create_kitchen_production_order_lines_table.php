<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_production_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_production_order_id');
            $table->unsignedBigInteger('ingredient_product_id');
            // Planned quantity, already converted to the ingredient's
            // stock unit (plan §5). planned_unit_id IS the stock unit.
            $table->decimal('planned_quantity', 12, 4);
            $table->unsignedBigInteger('planned_unit_id')->nullable();
            // Ingredient purchase_price at planning time.
            $table->decimal('planned_unit_cost', 15, 4)->default(0);
            $table->decimal('planned_cost', 15, 2)->default(0);
            // Filled in at completion (also in stock units).
            $table->decimal('actual_quantity', 12, 4)->nullable();
            // Ingredient purchase_price at completion time (snapshot).
            $table->decimal('actual_unit_cost', 15, 4)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();
            // Cleaning / preparation loss % copied from the recipe line.
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['kitchen_production_order_id', 'ingredient_product_id'], 'kpol_order_ingredient_unique');
            $table->index(['kitchen_production_order_id'], 'kpol_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_order_lines');
    }
};
