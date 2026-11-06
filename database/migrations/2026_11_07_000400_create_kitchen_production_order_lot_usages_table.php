<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Traceability (plan §5): which ingredient lots (FEFO) went into
        // which production order line — batch -> order -> ingredient lots.
        Schema::create('kitchen_production_order_lot_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_production_order_line_id');
            $table->unsignedBigInteger('product_expiry_lot_id');
            $table->decimal('quantity', 12, 4);
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['kitchen_production_order_line_id'], 'kpolu_line_idx');
            $table->index(['product_expiry_lot_id'], 'kpolu_lot_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_production_order_lot_usages');
    }
};
