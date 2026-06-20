<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_services')) {
            return;
        }

        Schema::table('product_services', function (Blueprint $table) {
            if (! Schema::hasColumn('product_services', 'batch_consumption_method')) {
                $table->string('batch_consumption_method', 10)
                    ->default('fefo')
                    ->after('batch_tracking');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_services')) {
            return;
        }

        Schema::table('product_services', function (Blueprint $table) {
            if (Schema::hasColumn('product_services', 'batch_consumption_method')) {
                $table->dropColumn('batch_consumption_method');
            }
        });
    }
};
