<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table) {
            if (! Schema::hasColumn('proformas', 'converted_purchase_id')) {
                $table->unsignedBigInteger('converted_purchase_id')->nullable()->after('converted_invoice_id');
            }
            if (! Schema::hasColumn('proformas', 'is_purchase_converted')) {
                $table->tinyInteger('is_purchase_converted')->default(0)->after('converted_purchase_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'proforma_id')) {
                $table->unsignedBigInteger('proforma_id')->nullable()->after('purchase_quotation_id');
            }
            if (! Schema::hasColumn('purchases', 'converted_invoice_id')) {
                $table->unsignedBigInteger('converted_invoice_id')->nullable()->after('converted_bill_id');
            }
            if (! Schema::hasColumn('purchases', 'is_invoice_converted')) {
                $table->tinyInteger('is_invoice_converted')->default(0)->after('converted_invoice_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'purchase_id')) {
                $table->unsignedBigInteger('purchase_id')->nullable()->after('proforma_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'purchase_id')) {
                $table->dropColumn('purchase_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'is_invoice_converted')) {
                $table->dropColumn('is_invoice_converted');
            }
            if (Schema::hasColumn('purchases', 'converted_invoice_id')) {
                $table->dropColumn('converted_invoice_id');
            }
            if (Schema::hasColumn('purchases', 'proforma_id')) {
                $table->dropColumn('proforma_id');
            }
        });

        Schema::table('proformas', function (Blueprint $table) {
            if (Schema::hasColumn('proformas', 'is_purchase_converted')) {
                $table->dropColumn('is_purchase_converted');
            }
            if (Schema::hasColumn('proformas', 'converted_purchase_id')) {
                $table->dropColumn('converted_purchase_id');
            }
        });
    }
};
