<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_sales_targets')) {
            return;
        }

        if (Schema::hasColumn('branch_sales_targets', 'month')) {
            DB::table('branch_sales_targets')
                ->where('period_type', 'month')
                ->where(function ($q) {
                    $q->whereNull('month')->orWhere('month', '');
                })
                ->update(['month' => DB::raw('`period_value`')]);

            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->dropForeign('branch_sales_targets_branch_id_foreign');
            });

            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->dropUnique('branch_sales_targets_branch_id_month_unique');
            });

            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->dropColumn('month');
            });

            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            });
        }

        $indexName = 'branch_sales_targets_branch_period_creator_unique';
        $hasIndex = collect(DB::select('SHOW INDEX FROM branch_sales_targets WHERE Key_name = ?', [$indexName]))->isNotEmpty();
        if (! $hasIndex) {
            Schema::table('branch_sales_targets', function (Blueprint $table) use ($indexName) {
                $table->unique(
                    ['branch_id', 'period_type', 'period_value', 'created_by'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('branch_sales_targets')) {
            return;
        }

        $indexName = 'branch_sales_targets_branch_period_creator_unique';
        $hasIndex = collect(DB::select('SHOW INDEX FROM branch_sales_targets WHERE Key_name = ?', [$indexName]))->isNotEmpty();
        if ($hasIndex) {
            Schema::table('branch_sales_targets', function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        }

        if (! Schema::hasColumn('branch_sales_targets', 'month')) {
            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->dropForeign('branch_sales_targets_branch_id_foreign');
            });

            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->char('month', 7)->nullable()->after('branch_id');
            });

            DB::table('branch_sales_targets')
                ->where('period_type', 'month')
                ->update(['month' => DB::raw('`period_value`')]);

            Schema::table('branch_sales_targets', function (Blueprint $table) {
                $table->unique(['branch_id', 'month']);
                $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            });
        }
    }
};
