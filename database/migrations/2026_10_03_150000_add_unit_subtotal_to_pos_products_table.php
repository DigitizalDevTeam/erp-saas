<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_products') || Schema::hasColumn('pos_products', 'unit_subtotal')) {
            return;
        }

        Schema::table('pos_products', function (Blueprint $table) {
            $table->decimal('unit_subtotal', 15, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_products') || ! Schema::hasColumn('pos_products', 'unit_subtotal')) {
            return;
        }

        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn('unit_subtotal');
        });
    }
};
