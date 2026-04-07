<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses') && ! Schema::hasColumn('expenses', 'chart_account_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('chart_account_id')->default(0)->after('payment_account_id');
            });
        }

        if (Schema::hasTable('expenses') && Schema::hasTable('expense_categories')
            && Schema::hasColumn('expenses', 'chart_account_id')
            && Schema::hasColumn('expense_categories', 'chart_account_id')) {
            DB::statement('UPDATE expenses e INNER JOIN expense_categories c ON c.id = e.expense_category_id SET e.chart_account_id = c.chart_account_id WHERE (e.chart_account_id IS NULL OR e.chart_account_id = 0) AND c.chart_account_id > 0');
        }

        if (Schema::hasTable('expense_categories') && Schema::hasColumn('expense_categories', 'chart_account_id')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->dropColumn('chart_account_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_categories') && ! Schema::hasColumn('expense_categories', 'chart_account_id')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('chart_account_id')->default(0)->after('name');
            });
        }

        if (Schema::hasTable('expenses') && Schema::hasTable('expense_categories')
            && Schema::hasColumn('expenses', 'chart_account_id')
            && Schema::hasColumn('expense_categories', 'chart_account_id')) {
            DB::statement('UPDATE expense_categories c INNER JOIN (
                SELECT expense_category_id, MIN(chart_account_id) AS chart_account_id FROM expenses WHERE chart_account_id > 0 GROUP BY expense_category_id
            ) x ON x.expense_category_id = c.id SET c.chart_account_id = x.chart_account_id WHERE c.chart_account_id = 0');
        }

        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'chart_account_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('chart_account_id');
            });
        }
    }
};
