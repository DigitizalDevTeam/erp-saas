<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products that do not affect stock (e.g. composite dishes): on POS sale only BOM ingredients are deducted.
     */
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->boolean('affect_stock')->default(true)->after('quantity')->comment('false = dish/composite; only BOM ingredients deduct on sale');
        });
    }

    public function down(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->dropColumn('affect_stock');
        });
    }
};
