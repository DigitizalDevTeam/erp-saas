<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_products', 'measurement_json')) {
            Schema::table('pos_products', function (Blueprint $table) {
                $table->text('measurement_json')->nullable()->after('identifiers');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_products', 'measurement_json')) {
            Schema::table('pos_products', function (Blueprint $table) {
                $table->dropColumn('measurement_json');
            });
        }
    }
};
