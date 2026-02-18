<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Phase 8: Optional customer name when opening table. */
    public function up(): void
    {
        Schema::table('pos_tables', function (Blueprint $table) {
            $table->string('customer_name', 255)->nullable()->after('current_guests');
        });
    }

    public function down(): void
    {
        Schema::table('pos_tables', function (Blueprint $table) {
            $table->dropColumn('customer_name');
        });
    }
};
