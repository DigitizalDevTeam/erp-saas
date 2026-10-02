<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_products') || Schema::hasColumn('pos_products', 'warehouse_id')) {
            return;
        }

        Schema::table('pos_products', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('pos_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_products') || ! Schema::hasColumn('pos_products', 'warehouse_id')) {
            return;
        }

        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn('warehouse_id');
        });
    }
};
