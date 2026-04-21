<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            if (!Schema::hasColumn('hotel_reservations', 'check_in_at')) {
                $table->dateTime('check_in_at')->nullable()->after('branch_id');
            }
            if (!Schema::hasColumn('hotel_reservations', 'check_out_at')) {
                $table->dateTime('check_out_at')->nullable()->after('check_in_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('hotel_reservations', 'check_out_at')) {
                $table->dropColumn('check_out_at');
            }
            if (Schema::hasColumn('hotel_reservations', 'check_in_at')) {
                $table->dropColumn('check_in_at');
            }
        });
    }
};
