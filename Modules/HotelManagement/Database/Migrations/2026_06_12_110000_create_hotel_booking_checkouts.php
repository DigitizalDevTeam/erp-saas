<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_booking_checkouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_booking_site_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('stripe_session_id', 255)->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 8);
            $table->json('booking_payload');
            $table->string('guest_token', 64)->nullable();
            $table->unsignedBigInteger('hotel_reservation_id')->nullable()->index();
            $table->string('stripe_payment_intent', 191)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_booking_checkouts');
    }
};
