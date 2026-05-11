<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('hotel_floor_id')->nullable()->after('hotel_room_type_id');
            $table->decimal('monthly_override_price', 15, 2)->nullable()->after('override_price');

            $table->foreign('hotel_floor_id')
                ->references('id')
                ->on('hotel_floors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_rooms', function (Blueprint $table) {
            $table->dropForeign(['hotel_floor_id']);
            $table->dropColumn(['hotel_floor_id', 'monthly_override_price']);
        });
    }
};
