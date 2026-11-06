<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan §11 risk 2: recipes work in grams and millilitres. Both stock
 * quantity columns were INTEGER, so a 150 g deduction would round to
 * zero in Phase 1. Convert them to decimal(15,4) before anything else
 * in the Kitchen Production module.
 *
 * NOTE: purchase_products.quantity is still INTEGER. It is deliberately
 * NOT changed here — purchase quantities are out of Phase 0 scope and
 * changing them touches the purchase flow. Flagged as a known limitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_products', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->default(0)->change();
        });

        Schema::table('stock_reports', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_products', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('stock_reports', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });
    }
};
