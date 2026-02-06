<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tables', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_guests')->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('pos_tables', function (Blueprint $table) {
            $table->dropColumn('current_guests');
        });
    }
};
