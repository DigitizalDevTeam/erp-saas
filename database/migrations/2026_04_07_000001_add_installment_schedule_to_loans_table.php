<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'schedule_mode')) {
                $table->string('schedule_mode', 32)->default('legacy')->after('reason');
            }
            if (! Schema::hasColumn('loans', 'principal_amount')) {
                $table->decimal('principal_amount', 15, 2)->nullable()->after('schedule_mode');
            }
            if (! Schema::hasColumn('loans', 'outstanding_principal')) {
                $table->decimal('outstanding_principal', 15, 2)->nullable()->after('principal_amount');
            }
            if (! Schema::hasColumn('loans', 'annual_interest_rate')) {
                $table->decimal('annual_interest_rate', 10, 4)->default(0)->after('outstanding_principal');
            }
            if (! Schema::hasColumn('loans', 'term_months')) {
                $table->unsignedSmallInteger('term_months')->nullable()->after('annual_interest_rate');
            }
            if (! Schema::hasColumn('loans', 'installments_paid')) {
                $table->unsignedSmallInteger('installments_paid')->default(0)->after('term_months');
            }
            if (! Schema::hasColumn('loans', 'first_deduction_month')) {
                $table->string('first_deduction_month', 7)->nullable()->after('installments_paid');
            }
            if (! Schema::hasColumn('loans', 'monthly_installment')) {
                $table->decimal('monthly_installment', 15, 2)->nullable()->after('first_deduction_month');
            }
            if (! Schema::hasColumn('loans', 'loan_status')) {
                $table->string('loan_status', 32)->default('active')->after('monthly_installment');
            }
        });

        if (! Schema::hasTable('loan_repayments')) {
            Schema::create('loan_repayments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loan_id');
                $table->unsignedBigInteger('payslip_id')->nullable();
                $table->string('salary_month', 7);
                $table->decimal('principal_paid', 15, 2)->default(0);
                $table->decimal('interest_paid', 15, 2)->default(0);
                $table->decimal('total_paid', 15, 2)->default(0);
                $table->decimal('balance_after_principal', 15, 2)->default(0);
                $table->integer('created_by')->default(0);
                $table->timestamps();

                $table->unique(['loan_id', 'salary_month'], 'loan_repayments_loan_month_unique');
                $table->index('payslip_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');

        Schema::table('loans', function (Blueprint $table) {
            foreach ([
                'schedule_mode', 'principal_amount', 'outstanding_principal', 'annual_interest_rate',
                'term_months', 'installments_paid', 'first_deduction_month', 'monthly_installment', 'loan_status',
            ] as $col) {
                if (Schema::hasColumn('loans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
