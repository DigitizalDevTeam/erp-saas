<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_production_orders', function (Blueprint $table) {
            $table->id();
            // Allocated on approval only; drafts hold no number (plan §5).
            $table->string('order_number', 30)->nullable();
            $table->unsignedBigInteger('kitchen_recipe_id');
            // The recipe version this order is locked to. Editing a recipe
            // never changes an order already in production (plan §11.3).
            $table->unsignedInteger('recipe_version')->default(1);
            $table->date('production_date');
            // hot_kitchen | pastry | cold_kitchen
            $table->string('section', 30)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            // The kitchen warehouse: ingredients are deducted here and the
            // finished batch is received here (decision D6).
            $table->unsignedBigInteger('warehouse_id');
            // Planned quantity in portions or kg, with its unit.
            $table->decimal('planned_quantity', 12, 4);
            $table->unsignedBigInteger('planned_unit_id')->nullable();
            $table->decimal('actual_yield_quantity', 12, 4)->nullable();
            $table->unsignedBigInteger('actual_yield_unit_id')->nullable();
            // draft | approved | in_preparation | completed | validated
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->text('notes')->nullable();
            // Planned cost, snapshotted at creation (purchase prices then).
            $table->decimal('planned_cost', 15, 2)->default(0);
            // Actual cost, snapshotted at completion — later price changes
            // never rewrite a completed order (plan §11.6).
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('cost_per_portion', 15, 2)->nullable();
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('food_cost_band', 20)->nullable();
            $table->date('batch_expiry_date')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            // Idempotency flags (plan §6): ingredients are deducted once.
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_received_at')->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'order_number'], 'kpo_created_by_number_unique');
            $table->index(['created_by', 'status'], 'kpo_created_by_status_idx');
            $table->index(['created_by', 'kitchen_recipe_id'], 'kpo_created_by_recipe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_orders');
    }
};
