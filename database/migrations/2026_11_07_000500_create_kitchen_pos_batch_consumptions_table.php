<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen Production Phase 2 (POS integration): which prepared batches a
 * POS line consumed, so a cancellation can put the prepared food back
 * (before preparation) and so produced - sold - lost reconciles per batch.
 *
 * One row per batch a line took from. quantity_restored tracks how much
 * of it was already put back, so a restore can never add the same
 * quantity twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_pos_batch_consumptions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('pos_product_id')->nullable();
            $table->integer('kitchen_production_batch_id');
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4);
            $table->decimal('quantity_restored', 12, 4)->default(0);
            $table->integer('warehouse_id');
            $table->integer('created_by');
            $table->timestamps();

            $table->index('pos_product_id', 'kpbc_line_idx');
            $table->index('kitchen_production_batch_id', 'kpbc_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_pos_batch_consumptions');
    }
};
