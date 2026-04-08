<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'salary_chart_account_id')) {
                $table->unsignedBigInteger('salary_chart_account_id')->nullable()->after('account');
            }
        });

        Schema::table('allowances', function (Blueprint $table) {
            if (! Schema::hasColumn('allowances', 'chart_account_id')) {
                $table->unsignedBigInteger('chart_account_id')->nullable()->after('amount');
            }
        });

        Schema::table('commissions', function (Blueprint $table) {
            if (! Schema::hasColumn('commissions', 'chart_account_id')) {
                $table->unsignedBigInteger('chart_account_id')->nullable()->after('amount');
            }
        });

        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'chart_account_id')) {
                $table->unsignedBigInteger('chart_account_id')->nullable()->after('amount');
            }
        });

        Schema::table('saturation_deductions', function (Blueprint $table) {
            if (! Schema::hasColumn('saturation_deductions', 'chart_account_id')) {
                $table->unsignedBigInteger('chart_account_id')->nullable()->after('amount');
            }
        });

        Schema::table('other_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('other_payments', 'chart_account_id')) {
                $table->unsignedBigInteger('chart_account_id')->nullable()->after('amount');
            }
        });

        Schema::table('overtimes', function (Blueprint $table) {
            if (! Schema::hasColumn('overtimes', 'chart_account_id')) {
                $table->unsignedBigInteger('chart_account_id')->nullable()->after('rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            if (Schema::hasColumn('overtimes', 'chart_account_id')) {
                $table->dropColumn('chart_account_id');
            }
        });
        Schema::table('other_payments', function (Blueprint $table) {
            if (Schema::hasColumn('other_payments', 'chart_account_id')) {
                $table->dropColumn('chart_account_id');
            }
        });
        Schema::table('saturation_deductions', function (Blueprint $table) {
            if (Schema::hasColumn('saturation_deductions', 'chart_account_id')) {
                $table->dropColumn('chart_account_id');
            }
        });
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'chart_account_id')) {
                $table->dropColumn('chart_account_id');
            }
        });
        Schema::table('commissions', function (Blueprint $table) {
            if (Schema::hasColumn('commissions', 'chart_account_id')) {
                $table->dropColumn('chart_account_id');
            }
        });
        Schema::table('allowances', function (Blueprint $table) {
            if (Schema::hasColumn('allowances', 'chart_account_id')) {
                $table->dropColumn('chart_account_id');
            }
        });
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'salary_chart_account_id')) {
                $table->dropColumn('salary_chart_account_id');
            }
        });
    }
};
