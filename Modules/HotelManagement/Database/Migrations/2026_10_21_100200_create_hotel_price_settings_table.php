<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_price_settings')) {
            Schema::create('hotel_price_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('created_by')->unique();
                // adults included in the room price before extra-person rules apply
                $table->unsignedInteger('included_adults')->default(2);
                // discount caps (percent of the reservation subtotal)
                $table->decimal('discount_cap_manager_percent', 5, 2)->default(25);
                $table->decimal('discount_cap_frontdesk_percent', 5, 2)->default(10);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_price_settings');
    }
};
