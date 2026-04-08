<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_products', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_products', 'measurement_json')) {
                $table->text('measurement_json')->nullable()->after('identifiers');
            }
        });

        Schema::table('pos_products', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_products', 'measurement_json')) {
                $table->text('measurement_json')->nullable()->after('identifiers');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (Schema::hasColumn('pos_products', 'measurement_json')) {
                $table->dropColumn('measurement_json');
            }
        });

        Schema::table('invoice_products', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_products', 'measurement_json')) {
                $table->dropColumn('measurement_json');
            }
        });
    }
};
