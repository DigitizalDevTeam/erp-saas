<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hotel_expenses') && ! Schema::hasColumn('hotel_expenses', 'hotel_cashier_shift_id')) {
            Schema::table('hotel_expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('hotel_cashier_shift_id')->nullable()->after('hotel_room_id');
                $table->index('hotel_cashier_shift_id');
            });
        }

        if (Schema::hasTable('hotel_cashier_shifts') && ! Schema::hasColumn('hotel_cashier_shifts', 'expenses_total')) {
            Schema::table('hotel_cashier_shifts', function (Blueprint $table) {
                $table->decimal('expenses_total', 15, 2)->default(0)->after('opening_float');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_expenses') && Schema::hasColumn('hotel_expenses', 'hotel_cashier_shift_id')) {
            Schema::table('hotel_expenses', function (Blueprint $table) {
                $table->dropIndex(['hotel_cashier_shift_id']);
                $table->dropColumn('hotel_cashier_shift_id');
            });
        }

        if (Schema::hasTable('hotel_cashier_shifts') && Schema::hasColumn('hotel_cashier_shifts', 'expenses_total')) {
            Schema::table('hotel_cashier_shifts', function (Blueprint $table) {
                $table->dropColumn('expenses_total');
            });
        }
    }
};
