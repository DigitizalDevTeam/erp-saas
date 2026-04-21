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
        Schema::create('hotel_folios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_stay_id');
            $table->string('folio_no')->unique();
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->enum('status', ['open', 'settled'])->default('open');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('hotel_stay_id')->references('id')->on('hotel_stays')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotel_folios');
    }
};
