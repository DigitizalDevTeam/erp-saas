<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_room_types', function (Blueprint $table) {
            $table->enum('unit_category', ['room', 'apartment'])->default('room')->after('description');
            $table->decimal('nightly_rate', 15, 2)->default(0)->after('base_price');
            $table->decimal('monthly_rate', 15, 2)->default(0)->after('nightly_rate');
            $table->enum('default_billing_mode', ['nightly', 'monthly'])->default('nightly')->after('monthly_rate');
        });

        DB::table('hotel_room_types')->update([
            'nightly_rate' => DB::raw('base_price'),
        ]);
    }

    public function down(): void
    {
        Schema::table('hotel_room_types', function (Blueprint $table) {
            $table->dropColumn(['unit_category', 'nightly_rate', 'monthly_rate', 'default_billing_mode']);
        });
    }
};
