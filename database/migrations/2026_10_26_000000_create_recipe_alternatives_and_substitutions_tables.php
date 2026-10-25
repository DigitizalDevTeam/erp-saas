<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_recipe_alternatives')) {
            Schema::create('product_recipe_alternatives', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_recipe_id');
                $table->unsignedBigInteger('alternative_product_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pos_line_ingredient_substitutions')) {
            Schema::create('pos_line_ingredient_substitutions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pos_product_id');
                $table->unsignedBigInteger('product_recipe_id');
                $table->unsignedBigInteger('main_ingredient_id');
                $table->unsignedBigInteger('alternative_id');
                $table->decimal('quantity', 12, 4)->default(0);
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_line_ingredient_substitutions');
        Schema::dropIfExists('product_recipe_alternatives');
    }
};
