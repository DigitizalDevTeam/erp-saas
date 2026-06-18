<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_room_consumption_logs')) {
            return;
        }

        Schema::table('hotel_room_consumption_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_room_consumption_logs', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->after('charge_type');
            }
            if (! Schema::hasColumn('hotel_room_consumption_logs', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('product_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hotel_room_consumption_logs')) {
            return;
        }

        Schema::table('hotel_room_consumption_logs', function (Blueprint $table) {
            foreach (['product_id', 'warehouse_id'] as $column) {
                if (Schema::hasColumn('hotel_room_consumption_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
