<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hotel_stays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_reservation_id');
            $table->unsignedBigInteger('hotel_guest_id');
            $table->unsignedBigInteger('hotel_room_id');
            $table->dateTime('check_in_at');
            $table->dateTime('expected_check_out_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->enum('status', ['in_house', 'checked_out'])->default('in_house');
            $table->decimal('extra_charge', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('hotel_reservation_id')->references('id')->on('hotel_reservations')->onDelete('cascade');
            $table->foreign('hotel_room_id')->references('id')->on('hotel_rooms')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_stays');
    }
};
