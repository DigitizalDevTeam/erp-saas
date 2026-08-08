<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'client_po_number')) {
                $table->string('client_po_number')->nullable()->after('ref_number');
            }
            if (! Schema::hasColumn('invoices', 'client_po_date')) {
                $table->date('client_po_date')->nullable()->after('client_po_number');
            }
            if (! Schema::hasColumn('invoices', 'client_po_details')) {
                $table->text('client_po_details')->nullable()->after('client_po_date');
            }
            if (! Schema::hasColumn('invoices', 'quotation_reference')) {
                $table->string('quotation_reference')->nullable()->after('client_po_details');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['client_po_number', 'client_po_date', 'client_po_details', 'quotation_reference'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
