<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'converted_invoice_id')) {
                $table->unsignedBigInteger('converted_invoice_id')->nullable()->after('converted_proforma_id');
            }
            if (! Schema::hasColumn('quotations', 'converted_purchase_id')) {
                $table->unsignedBigInteger('converted_purchase_id')->nullable()->after('converted_invoice_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'quotation_id')) {
                $table->unsignedBigInteger('quotation_id')->nullable()->after('proforma_id');
            }
        });

        Schema::table('purchase_quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_quotations', 'converted_proforma_id')) {
                $table->unsignedBigInteger('converted_proforma_id')->nullable()->after('converted_purchase_id');
            }
        });

        Schema::table('proformas', function (Blueprint $table) {
            if (! Schema::hasColumn('proformas', 'purchase_quotation_id')) {
                $table->unsignedBigInteger('purchase_quotation_id')->nullable()->after('quotation_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            foreach (['converted_invoice_id', 'converted_purchase_id'] as $col) {
                if (Schema::hasColumn('quotations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'quotation_id')) {
                $table->dropColumn('quotation_id');
            }
        });

        Schema::table('purchase_quotations', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_quotations', 'converted_proforma_id')) {
                $table->dropColumn('converted_proforma_id');
            }
        });

        Schema::table('proformas', function (Blueprint $table) {
            if (Schema::hasColumn('proformas', 'purchase_quotation_id')) {
                $table->dropColumn('purchase_quotation_id');
            }
        });
    }
};
