<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stock module: when enabled, company sees Product & Services, Product Stock, Warehouse.
     * POS-only package typically has pos=1 + stock=1; other modules hidden.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('plans', 'stock')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->integer('stock')->default(0)->after('pos');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }
};
