<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_unit_id');
            $table->unsignedBigInteger('to_unit_id');
            // Factor: quantity in from_unit * factor = quantity in to_unit.
            // Example: 1 bag (25 kg) -> kg has factor 25; 1 kg -> g has factor 1000.
            $table->decimal('factor', 18, 6);
            // Null = conversion applies company-wide; set for a single product
            // when only that product is bought in the from_unit.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'from_unit_id', 'to_unit_id', 'product_id']);
            $table->index(['created_by', 'from_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_unit_conversions');
    }
};
