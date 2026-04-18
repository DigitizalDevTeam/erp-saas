<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_slips', 'payroll_journal_id')) {
                $table->unsignedBigInteger('payroll_journal_id')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            if (Schema::hasColumn('pay_slips', 'payroll_journal_id')) {
                $table->dropColumn('payroll_journal_id');
            }
        });
    }
};
