<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_production_batches', function (Blueprint $table) {
            $table->id();
            // Carries the order number: {ORDER}-B1 (plan §5 — one order
            // is one recipe version is one batch).
            $table->string('batch_number', 50);
            $table->unsignedBigInteger('kitchen_production_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity_produced', 12, 4);
            // Falls as dishes are sold (Phase 2 consumes it FEFO).
            // Produced - sold - lost = remaining (plan §9).
            $table->decimal('quantity_remaining', 12, 4);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->date('expiry_date')->nullable();
            // Cost per unit of the produced preparation.
            $table->decimal('unit_cost', 15, 4)->nullable();
            // Actual production cost of the whole batch (snapshot).
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'batch_number'], 'kpb_created_by_number_unique');
            $table->index(['created_by', 'product_id', 'warehouse_id'], 'kpb_created_by_prod_wh_idx');
            $table->index(['expiry_date'], 'kpb_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_batches');
    }
};
