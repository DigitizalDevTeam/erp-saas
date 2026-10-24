<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opt-in per product: selling more than on hand is allowed and
        // stock may go below zero. Default OFF — every existing product
        // keeps the old clamp-at-zero behaviour until the user ticks it.
        // Hidden (and forced off) for kit and service types on the form.
        if (Schema::hasTable('product_services') && ! Schema::hasColumn('product_services', 'allow_negative_stock')) {
            Schema::table('product_services', function (Blueprint $table) {
                $table->boolean('allow_negative_stock')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_services') && Schema::hasColumn('product_services', 'allow_negative_stock')) {
            Schema::table('product_services', function (Blueprint $table) {
                $table->dropColumn('allow_negative_stock');
            });
        }
    }
};
