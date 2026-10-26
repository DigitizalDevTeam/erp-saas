<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_shifts') && ! Schema::hasColumn('pos_shifts', 'report_warehouse_ids')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                $table->json('report_warehouse_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_shifts') && Schema::hasColumn('pos_shifts', 'report_warehouse_ids')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                $table->dropColumn('report_warehouse_ids');
            });
        }
    }
};
