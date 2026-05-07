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
        Schema::create('hotel_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_no')->unique();
            $table->unsignedBigInteger('hotel_guest_id');
            $table->unsignedBigInteger('hotel_room_type_id');
            $table->unsignedBigInteger('hotel_room_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->enum('source', ['manual', 'walkin', 'online', 'ota'])->default('manual');
            $table->enum('status', ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'])->default('pending');
            $table->decimal('rate_per_night', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('hotel_room_type_id')->references('id')->on('hotel_room_types')->onDelete('restrict');
            $table->foreign('hotel_room_id')->references('id')->on('hotel_rooms')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_reservations');
    }
};
