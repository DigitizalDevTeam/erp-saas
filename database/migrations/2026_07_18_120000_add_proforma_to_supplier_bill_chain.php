<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table) {
            if (! Schema::hasColumn('proformas', 'converted_bill_id')) {
                $table->unsignedBigInteger('converted_bill_id')->nullable()->after('converted_purchase_id');
            }
            if (! Schema::hasColumn('proformas', 'is_bill_converted')) {
                $table->tinyInteger('is_bill_converted')->default(0)->after('converted_bill_id');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'proforma_id')) {
                $table->unsignedBigInteger('proforma_id')->nullable()->after('purchase_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'proforma_id')) {
                $table->dropColumn('proforma_id');
            }
        });

        Schema::table('proformas', function (Blueprint $table) {
            if (Schema::hasColumn('proformas', 'is_bill_converted')) {
                $table->dropColumn('is_bill_converted');
            }
            if (Schema::hasColumn('proformas', 'converted_bill_id')) {
                $table->dropColumn('converted_bill_id');
            }
        });
    }
};
