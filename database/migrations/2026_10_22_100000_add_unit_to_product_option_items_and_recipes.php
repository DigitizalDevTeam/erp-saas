<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Measurement unit of the per-sale quantity (ML, G, KG, UNIT, ...)
        // chosen per option row / recipe row in the product form.
        // Codes come from App\Support\MeasurementUnits.
        if (Schema::hasTable('product_option_items') && ! Schema::hasColumn('product_option_items', 'unit')) {
            Schema::table('product_option_items', function (Blueprint $table) {
                $table->string('unit', 16)->nullable()->after('quantity');
            });
        }

        if (Schema::hasTable('product_recipes') && ! Schema::hasColumn('product_recipes', 'unit')) {
            Schema::table('product_recipes', function (Blueprint $table) {
                $table->string('unit', 16)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_option_items') && Schema::hasColumn('product_option_items', 'unit')) {
            Schema::table('product_option_items', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }

        if (Schema::hasTable('product_recipes') && Schema::hasColumn('product_recipes', 'unit')) {
            Schema::table('product_recipes', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }
};
