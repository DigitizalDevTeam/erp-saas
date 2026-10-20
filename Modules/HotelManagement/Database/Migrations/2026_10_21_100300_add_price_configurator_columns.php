<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_reservations', 'extra_adults')) {
                $table->unsignedInteger('extra_adults')->default(0)->after('adults');
            }
        });

        Schema::table('hotel_room_types', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_room_types', 'included_adults')) {
                // null = use the global Price Configurator setting
                $table->unsignedInteger('included_adults')->nullable()->after('max_occupancy');
            }
        });

        Schema::table('hotel_reservation_addons', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_reservation_addons', 'rule_id')) {
                // the price rule that resolved this row's unit price, when one matched
                $table->unsignedBigInteger('rule_id')->nullable()->after('hotel_addon_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('hotel_reservations', 'extra_adults')) {
                $table->dropColumn('extra_adults');
            }
        });

        Schema::table('hotel_room_types', function (Blueprint $table) {
            if (Schema::hasColumn('hotel_room_types', 'included_adults')) {
                $table->dropColumn('included_adults');
            }
        });

        Schema::table('hotel_reservation_addons', function (Blueprint $table) {
            if (Schema::hasColumn('hotel_reservation_addons', 'rule_id')) {
                $table->dropColumn('rule_id');
            }
        });
    }
};
