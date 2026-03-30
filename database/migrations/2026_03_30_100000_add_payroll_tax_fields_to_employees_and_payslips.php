<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'dependents')) {
                $table->unsignedInteger('dependents')->default(0)->after('tax_payer_id');
            }
            if (!Schema::hasColumn('employees', 'inss_employer_pays_all')) {
                $table->boolean('inss_employer_pays_all')->default(false)->after('dependents');
            }
        });

        Schema::table('pay_slips', function (Blueprint $table) {
            if (!Schema::hasColumn('pay_slips', 'inss_employee_amount')) {
                $table->decimal('inss_employee_amount', 15, 2)->default(0)->after('net_payble');
            }
            if (!Schema::hasColumn('pay_slips', 'inss_employer_amount')) {
                $table->decimal('inss_employer_amount', 15, 2)->default(0)->after('inss_employee_amount');
            }
            if (!Schema::hasColumn('pay_slips', 'irps_amount')) {
                $table->decimal('irps_amount', 15, 2)->default(0)->after('inss_employer_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            if (Schema::hasColumn('pay_slips', 'irps_amount')) {
                $table->dropColumn('irps_amount');
            }
            if (Schema::hasColumn('pay_slips', 'inss_employer_amount')) {
                $table->dropColumn('inss_employer_amount');
            }
            if (Schema::hasColumn('pay_slips', 'inss_employee_amount')) {
                $table->dropColumn('inss_employee_amount');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'inss_employer_pays_all')) {
                $table->dropColumn('inss_employer_pays_all');
            }
            if (Schema::hasColumn('employees', 'dependents')) {
                $table->dropColumn('dependents');
            }
        });
    }
};

