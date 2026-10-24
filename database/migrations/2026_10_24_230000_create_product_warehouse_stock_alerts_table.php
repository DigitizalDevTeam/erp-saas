<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-warehouse low / max stock alert levels for one product.
        // Unlike product_services.low_stock_threshold / max_stock_threshold
        // (one pair per product for all warehouses), each row here holds
        // a DIFFERENT pair for one warehouse. An empty (null) value falls
        // back to the product's own threshold, then to the system default.
        // A row with both values null is never kept — deleting it restores
        // the fallback behaviour instead of storing a dead row.
        if (! Schema::hasTable('product_warehouse_stock_alerts')) {
            Schema::create('product_warehouse_stock_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->integer('low_stock_threshold')->nullable();
                $table->integer('max_stock_threshold')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->unique(['product_id', 'warehouse_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warehouse_stock_alerts');
    }
};
