<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_guests')) {
            return;
        }

        Schema::table('hotel_guests', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_guests', 'passport_number')) {
                $table->string('passport_number', 100)->nullable()->after('id_number');
            }
            if (! Schema::hasColumn('hotel_guests', 'visa_number')) {
                $table->string('visa_number', 100)->nullable()->after('passport_number');
            }
            if (! Schema::hasColumn('hotel_guests', 'country')) {
                $table->string('country', 100)->nullable()->after('nationality');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hotel_guests')) {
            return;
        }

        Schema::table('hotel_guests', function (Blueprint $table) {
            foreach (['passport_number', 'visa_number', 'country'] as $column) {
                if (Schema::hasColumn('hotel_guests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
