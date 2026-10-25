<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_recipe_alternatives') && ! Schema::hasColumn('product_recipe_alternatives', 'quantity')) {
            Schema::table('product_recipe_alternatives', function (Blueprint $table) {
                $table->decimal('quantity', 12, 4)->nullable()->after('alternative_product_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_recipe_alternatives') && Schema::hasColumn('product_recipe_alternatives', 'quantity')) {
            Schema::table('product_recipe_alternatives', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }
};
