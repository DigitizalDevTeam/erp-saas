<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Print zone for restaurant POS: kitchen, bar, or null (both / receipt only).
     */
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->string('print_zone', 20)->nullable()->after('type'); // kitchen | bar | null
        });
    }

    public function down(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->dropColumn('print_zone');
        });
    }
};
