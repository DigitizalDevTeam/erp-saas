<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            if (! Schema::hasColumn('product_services', 'unit_length')) {
                $table->decimal('unit_length', 16, 6)->nullable()->after('unit_area_sqm');
            }
            if (! Schema::hasColumn('product_services', 'unit_width')) {
                $table->decimal('unit_width', 16, 6)->nullable()->after('unit_length');
            }
            if (! Schema::hasColumn('product_services', 'unit_height')) {
                $table->decimal('unit_height', 16, 6)->nullable()->after('unit_width');
            }
            if (! Schema::hasColumn('product_services', 'dimension_unit')) {
                $table->string('dimension_unit', 8)->nullable()->after('unit_height');
            }
            if (! Schema::hasColumn('product_services', 'volumetric_divisor')) {
                $table->unsignedInteger('volumetric_divisor')->nullable()->after('dimension_unit');
            }
            if (! Schema::hasColumn('product_services', 'unit_volumetric_weight')) {
                $table->decimal('unit_volumetric_weight', 16, 6)->nullable()->after('volumetric_divisor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            foreach (['unit_volumetric_weight', 'volumetric_divisor', 'dimension_unit', 'unit_height', 'unit_width', 'unit_length'] as $col) {
                if (Schema::hasColumn('product_services', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

