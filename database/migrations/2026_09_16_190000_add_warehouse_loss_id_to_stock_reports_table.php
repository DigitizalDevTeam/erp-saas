<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_reports', 'warehouse_loss_id')) {
                $table->unsignedBigInteger('warehouse_loss_id')->nullable()->default(null)->after('type_id');
                $table->index('warehouse_loss_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_reports', function (Blueprint $table) {
            if (Schema::hasColumn('stock_reports', 'warehouse_loss_id')) {
                $table->dropIndex(['warehouse_loss_id']);
                $table->dropColumn('warehouse_loss_id');
            }
        });
    }
};
