<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_cost_alerts', function (Blueprint $table) {
            $table->id();
            // band_bad | band_very_bad | worse_than_planned |
            // dish_band_bad | dish_band_very_bad (plan §7).
            $table->string('alert_type', 30);
            // The dish the alert is about (null for order-only alerts
            // that carry no dish context — never happens in practice,
            // the order always has a recipe/product).
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('kitchen_recipe_id')->nullable();
            // The completed order that fired the alert (null for
            // dish-level alerts raised by refreshDishAlerts).
            $table->unsignedBigInteger('kitchen_production_order_id')->nullable();
            // The food cost % / band that fired the alert.
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('band', 20)->nullable();
            // The planned food cost % / band, for worse_than_planned
            // alerts (null for band alerts).
            $table->decimal('planned_food_cost_percent', 5, 2)->nullable();
            $table->string('planned_food_cost_band', 20)->nullable();
            // open | acknowledged. No email is sent in this phase (plan
            // §7) — the alert is a record the manager sees and clears.
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('note')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['created_by', 'status'], 'kca_created_by_status_idx');
            $table->index(['kitchen_production_order_id'], 'kca_order_idx');
            $table->index(['product_id'], 'kca_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_cost_alerts');
    }
};
