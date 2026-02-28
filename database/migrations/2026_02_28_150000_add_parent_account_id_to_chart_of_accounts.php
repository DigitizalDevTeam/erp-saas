<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('chart_of_accounts', 'parent_account_id')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_account_id')->nullable()->after('parent');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('chart_of_accounts', 'parent_account_id')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropColumn('parent_account_id');
            });
        }
    }
};
