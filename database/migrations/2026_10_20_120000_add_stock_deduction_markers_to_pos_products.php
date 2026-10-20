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

        Schema::table('pos_products', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_products', 'stock_deducted_at')) {
                $table->timestamp('stock_deducted_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('pos_products', 'stock_restored_at')) {
                $table->timestamp('stock_restored_at')->nullable()->after('stock_deducted_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_products')) {
            return;
        }

        Schema::table('pos_products', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('pos_products', 'stock_deducted_at')) {
                $cols[] = 'stock_deducted_at';
            }
            if (Schema::hasColumn('pos_products', 'stock_restored_at')) {
                $cols[] = 'stock_restored_at';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
