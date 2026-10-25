<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_products')) {
            return;
        }
        if (Schema::hasColumn('pos_products', 'negative_stock_authorized_by')) {
            return;
        }
        Schema::table('pos_products', function (Blueprint $table) {
            // Manager who authorized this line to sell below zero stock
            // (null = normal sale). Read at deduction time so that one
            // line may go negative without changing the product's flag.
            $table->unsignedBigInteger('negative_stock_authorized_by')->nullable()->after('stock_restored_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_products')) {
            return;
        }
        if (! Schema::hasColumn('pos_products', 'negative_stock_authorized_by')) {
            return;
        }
        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn('negative_stock_authorized_by');
        });
    }
};
