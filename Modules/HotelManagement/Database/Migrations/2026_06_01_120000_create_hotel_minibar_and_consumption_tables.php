<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_minibar_items')) {
            Schema::create('hotel_minibar_items', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['created_by', 'is_active']);
            });
        }

        if (! Schema::hasTable('hotel_room_consumption_logs')) {
            Schema::create('hotel_room_consumption_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hotel_room_id');
                $table->unsignedBigInteger('hotel_stay_id');
                $table->unsignedBigInteger('hotel_folio_id');
                $table->unsignedBigInteger('hotel_folio_line_id');
                $table->string('charge_type', 32);
                $table->date('service_date');
                $table->string('description');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('line_total', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['hotel_room_id', 'service_date']);
                $table->index(['hotel_stay_id', 'service_date']);
                $table->index('hotel_folio_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_room_consumption_logs');
        Schema::dropIfExists('hotel_minibar_items');
    }
};
