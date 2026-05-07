<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_ota_settings', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 50);
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->string('property_id', 191)->nullable();
            $table->text('webhook_url')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['created_by', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_ota_settings');
    }
};
