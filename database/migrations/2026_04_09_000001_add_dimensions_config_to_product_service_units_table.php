<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_service_units', function (Blueprint $table) {
            if (! Schema::hasColumn('product_service_units', 'dimension_mode')) {
                // none | area_sqm | volume_cbm | volumetric_weight
                $table->string('dimension_mode', 32)->nullable()->after('name');
            }
            if (! Schema::hasColumn('product_service_units', 'length_unit')) {
                // cm | m (used for L/W/H or W/L)
                $table->string('length_unit', 8)->nullable()->after('dimension_mode');
            }
            if (! Schema::hasColumn('product_service_units', 'volumetric_divisor')) {
                // 4000/5000/6000 etc (for (L*W*H)/divisor), typically with cm inputs
                $table->unsignedInteger('volumetric_divisor')->nullable()->after('length_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_service_units', function (Blueprint $table) {
            foreach (['volumetric_divisor', 'length_unit', 'dimension_mode'] as $col) {
                if (Schema::hasColumn('product_service_units', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

