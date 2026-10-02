<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_services') || Schema::hasColumn('product_services', 'show_on_pos')) {
            return;
        }

        Schema::table('product_services', function (Blueprint $table) {
            $table->boolean('show_on_pos')->default(true)->after('type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_services') || ! Schema::hasColumn('product_services', 'show_on_pos')) {
            return;
        }

        Schema::table('product_services', function (Blueprint $table) {
            $table->dropColumn('show_on_pos');
        });
    }
};
