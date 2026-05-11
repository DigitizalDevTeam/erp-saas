<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_guests', function (Blueprint $table) {
            $table->unsignedBigInteger('preferred_hotel_floor_id')->nullable()->after('preferred_floor');
            $table->text('allergies')->nullable()->after('notes');
            $table->text('preferences')->nullable()->after('allergies');
            $table->text('drink_preferences')->nullable()->after('preferences');

            $table->foreign('preferred_hotel_floor_id')
                ->references('id')
                ->on('hotel_floors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_guests', function (Blueprint $table) {
            $table->dropForeign(['preferred_hotel_floor_id']);
            $table->dropColumn(['preferred_hotel_floor_id', 'allergies', 'preferences', 'drink_preferences']);
        });
    }
};
