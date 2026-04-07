<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            if (! Schema::hasColumn('product_services', 'variants_enabled')) {
                $table->boolean('variants_enabled')->default(false)->after('batch_tracking');
            }
            if (! Schema::hasColumn('product_services', 'variant_size_label')) {
                $table->string('variant_size_label', 64)->nullable()->after('variants_enabled');
            }
            if (! Schema::hasColumn('product_services', 'variant_color_label')) {
                $table->string('variant_color_label', 64)->nullable()->after('variant_size_label');
            }
            if (! Schema::hasColumn('product_services', 'unit_volume_cbm')) {
                $table->decimal('unit_volume_cbm', 16, 6)->nullable()->after('variant_color_label');
            }
            if (! Schema::hasColumn('product_services', 'unit_area_sqm')) {
                $table->decimal('unit_area_sqm', 16, 6)->nullable()->after('unit_volume_cbm');
            }
        });

        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_service_id');
                $table->string('size', 64)->default('');
                $table->string('color', 64)->default('');
                $table->string('sku', 191);
                $table->decimal('sale_price', 16, 2)->nullable();
                $table->decimal('purchase_price', 16, 2)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->foreign('product_service_id')
                    ->references('id')
                    ->on('product_services')
                    ->cascadeOnDelete();
                $table->index(['created_by', 'product_service_id']);
                $table->unique(['product_service_id', 'size', 'color'], 'product_variants_unique_dims');
            });
        }

        Schema::table('warehouse_products', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_products', 'product_variant_id')) {
                $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
                $table->foreign('product_variant_id')
                    ->references('id')
                    ->on('product_variants')
                    ->nullOnDelete();
                $table->index(['warehouse_id', 'product_id', 'product_variant_id'], 'wp_wh_prod_variant_idx');
            }
        });

        Schema::table('purchase_products', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_products', 'product_variant_id')) {
                $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
                $table->foreign('product_variant_id')
                    ->references('id')
                    ->on('product_variants')
                    ->nullOnDelete();
            }
        });

        Schema::table('pos_products', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_products', 'product_variant_id')) {
                $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');
                $table->foreign('product_variant_id')
                    ->references('id')
                    ->on('product_variants')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (Schema::hasColumn('pos_products', 'product_variant_id')) {
                $table->dropForeign(['product_variant_id']);
                $table->dropColumn('product_variant_id');
            }
        });
        Schema::table('purchase_products', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_products', 'product_variant_id')) {
                $table->dropForeign(['product_variant_id']);
                $table->dropColumn('product_variant_id');
            }
        });
        Schema::table('warehouse_products', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_products', 'product_variant_id')) {
                $table->dropForeign(['product_variant_id']);
                $table->dropIndex('wp_wh_prod_variant_idx');
                $table->dropColumn('product_variant_id');
            }
        });
        Schema::dropIfExists('product_variants');
        Schema::table('product_services', function (Blueprint $table) {
            foreach (['variants_enabled', 'variant_size_label', 'variant_color_label', 'unit_volume_cbm', 'unit_area_sqm'] as $col) {
                if (Schema::hasColumn('product_services', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
