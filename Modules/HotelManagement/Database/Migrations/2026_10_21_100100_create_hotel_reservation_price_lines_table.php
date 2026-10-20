<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_reservation_price_lines')) {
            Schema::create('hotel_reservation_price_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('created_by')->index();
                $table->unsignedBigInteger('hotel_reservation_id')->index();
                // child | extra_person (addons keep their own snapshot table)
                $table->string('kind', 32)->index();
                $table->string('label');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->unsignedInteger('qty')->default(0);
                $table->decimal('unit_price', 15, 2)->default(0);
                // billing units the price was multiplied by (nights, or months on monthly stays)
                $table->unsignedInteger('units')->default(1);
                $table->decimal('amount', 15, 2)->default(0);
                // set once the line has been posted to the folio (double-post guard)
                $table->unsignedBigInteger('hotel_folio_line_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_reservation_price_lines');
    }
};
