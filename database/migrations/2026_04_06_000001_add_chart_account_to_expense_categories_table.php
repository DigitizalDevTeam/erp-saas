<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expense_categories') && ! Schema::hasColumn('expense_categories', 'chart_account_id')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('chart_account_id')->default(0)->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_categories') && Schema::hasColumn('expense_categories', 'chart_account_id')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->dropColumn('chart_account_id');
            });
        }
    }
};
