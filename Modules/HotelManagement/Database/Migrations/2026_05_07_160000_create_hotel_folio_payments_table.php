<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_folio_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_folio_id');
            $table->decimal('amount', 15, 2);
            $table->string('method', 50);
            $table->string('reference', 191)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->foreign('hotel_folio_id')->references('id')->on('hotel_folios')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_folio_payments');
    }
};
