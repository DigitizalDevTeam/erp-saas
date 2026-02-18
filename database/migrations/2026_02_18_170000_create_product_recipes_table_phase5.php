<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: Recipe/BOM – product (dish) → ingredients with quantity and unit.
     */
    public function up(): void
    {
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id'); // the sellable product (e.g. Grilled Chicken)
            $table->unsignedBigInteger('ingredient_product_id'); // the stock product (e.g. Chicken raw)
            $table->decimal('quantity', 16, 4)->default(1); // per 1 unit of product
            $table->unsignedBigInteger('unit_id')->nullable(); // optional, for display/conversion
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipes');
    }
};
