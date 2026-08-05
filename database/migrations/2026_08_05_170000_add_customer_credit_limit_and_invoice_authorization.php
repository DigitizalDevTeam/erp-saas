<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'credit_limit')) {
                $table->decimal('credit_limit', 15, 2)->default(0)->after('credit_terms');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'credit_hold_reasons')) {
                $table->text('credit_hold_reasons')->nullable()->after('status');
            }
            if (! Schema::hasColumn('invoices', 'authorized_by')) {
                $table->unsignedBigInteger('authorized_by')->nullable()->after('credit_hold_reasons');
            }
            if (! Schema::hasColumn('invoices', 'authorized_at')) {
                $table->timestamp('authorized_at')->nullable()->after('authorized_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'credit_limit')) {
                $table->dropColumn('credit_limit');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('invoices', 'credit_hold_reasons') ? 'credit_hold_reasons' : null,
                Schema::hasColumn('invoices', 'authorized_by') ? 'authorized_by' : null,
                Schema::hasColumn('invoices', 'authorized_at') ? 'authorized_at' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
