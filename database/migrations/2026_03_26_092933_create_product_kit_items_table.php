<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_kit_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kit_product_id'); // product_services.id where type = kit
            $table->unsignedBigInteger('component_product_id'); // product_services.id (stock item)
            $table->decimal('quantity', 16, 4)->default(1); // per 1 kit sold
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();

            $table->index(['created_by', 'kit_product_id']);
            $table->index(['created_by', 'component_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_kit_items');
    }
};
