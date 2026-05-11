<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            $table->decimal('monthly_rate', 15, 2)->default(0)->after('rate');
            $table->enum('billing_mode', ['nightly', 'monthly', 'both'])->default('nightly')->after('monthly_rate');
            $table->integer('priority')->default(100)->after('billing_mode');
        });

        DB::table('hotel_rate_plans')->update([
            'billing_mode' => 'nightly',
            'priority' => 100,
        ]);
    }

    public function down(): void
    {
        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            $table->dropColumn(['monthly_rate', 'billing_mode', 'priority']);
        });
    }
};
