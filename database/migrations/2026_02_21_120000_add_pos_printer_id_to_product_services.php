<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_printer_id')->nullable()->after('print_zone');
        });
    }

    public function down(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->dropColumn('pos_printer_id');
        });
    }
};
