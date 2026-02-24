<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_sales_targets', function (Blueprint $table) {
            $table->string('period_type', 10)->default('month')->after('branch_id');
            $table->string('period_value', 20)->nullable()->after('period_type');
        });

        \DB::table('branch_sales_targets')->update([
            'period_value' => \DB::raw('`month`'),
        ]);

        Schema::table('branch_sales_targets', function (Blueprint $table) {
            $table->string('period_value', 20)->nullable(false)->change();
        });

        Schema::table('branch_sales_targets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'month']);
            $table->dropColumn('month');
        });
    }

    public function down(): void
    {
        Schema::table('branch_sales_targets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'period_type', 'period_value']);
            $table->char('month', 7)->nullable()->after('branch_id');
        });

        \DB::table('branch_sales_targets')->where('period_type', 'month')->update([
            'month' => \DB::raw('period_value'),
        ]);
        \DB::table('branch_sales_targets')->where('period_type', '!=', 'month')->update(['month' => date('Y-m')]);

        Schema::table('branch_sales_targets', function (Blueprint $table) {
            $table->char('month', 7)->nullable(false)->change();
            $table->dropColumn(['period_type', 'period_value']);
            $table->unique(['branch_id', 'month']);
        });
    }
};
