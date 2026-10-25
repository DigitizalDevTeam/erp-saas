<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-food default preparation time (minutes) for restaurant
        // orders. Null means "no own default" — the send-to-production
        // flow then falls back to the global default. Existing products
        // keep the old behaviour until a time is set on them.
        if (Schema::hasTable('product_services') && ! Schema::hasColumn('product_services', 'preparation_time')) {
            Schema::table('product_services', function (Blueprint $table) {
                $table->unsignedSmallInteger('preparation_time')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_services') && Schema::hasColumn('product_services', 'preparation_time')) {
            Schema::table('product_services', function (Blueprint $table) {
                $table->dropColumn('preparation_time');
            });
        }
    }
};
