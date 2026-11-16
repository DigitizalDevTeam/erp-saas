<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which warehouse an invoice's stock was deducted from,
     * so a void restores to the SAME warehouse (Issue 8).
     */
    public function up(): void
    {
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'stock_deducted_warehouse_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('stock_deducted_warehouse_id')->nullable()->after('stock_deducted_via');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'stock_deducted_warehouse_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('stock_deducted_warehouse_id');
            });
        }
    }
};
