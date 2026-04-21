<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hotel_reservations') && Schema::hasTable('hotel_guests')) {
            Schema::table('hotel_reservations', function (Blueprint $table) {
                $table->foreign('hotel_guest_id')->references('id')->on('hotel_guests')->onDelete('cascade');
            });
        }

        if (Schema::hasTable('hotel_stays') && Schema::hasTable('hotel_guests')) {
            Schema::table('hotel_stays', function (Blueprint $table) {
                $table->foreign('hotel_guest_id')->references('id')->on('hotel_guests')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_stays')) {
            Schema::table('hotel_stays', function (Blueprint $table) {
                $table->dropForeign(['hotel_guest_id']);
            });
        }

        if (Schema::hasTable('hotel_reservations')) {
            Schema::table('hotel_reservations', function (Blueprint $table) {
                $table->dropForeign(['hotel_guest_id']);
            });
        }
    }
};
