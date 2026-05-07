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
        Schema::create('hotel_housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_room_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_housekeeping_tasks');
    }
};
