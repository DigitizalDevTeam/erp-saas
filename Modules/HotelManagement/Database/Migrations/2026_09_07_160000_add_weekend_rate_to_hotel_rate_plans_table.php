<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_rate_plans', 'weekend_rate')) {
                $table->decimal('weekend_rate', 15, 2)->nullable()->after('rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            if (Schema::hasColumn('hotel_rate_plans', 'weekend_rate')) {
                $table->dropColumn('weekend_rate');
            }
        });
    }
};
