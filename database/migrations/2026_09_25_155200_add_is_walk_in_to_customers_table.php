<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * POS walk-in customers stay available in POS only; excluded from document/customer lists.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'is_walk_in')) {
                $table->boolean('is_walk_in')->default(false)->after('is_active')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'is_walk_in')) {
                $table->dropColumn('is_walk_in');
            }
        });
    }
};
