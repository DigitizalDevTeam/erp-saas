<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_losses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_losses', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 4)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('warehouse_losses', 'cost_of_goods')) {
                $table->decimal('cost_of_goods', 15, 2)->default(0)->after('unit_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_losses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_losses', 'cost_of_goods')) {
                $table->dropColumn('cost_of_goods');
            }
            if (Schema::hasColumn('warehouse_losses', 'unit_cost')) {
                $table->dropColumn('unit_cost');
            }
        });
    }
};
