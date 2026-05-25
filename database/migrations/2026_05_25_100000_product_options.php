<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            if (! Schema::hasColumn('product_services', 'options_enabled')) {
                $table->boolean('options_enabled')->default(false)->after('variants_enabled');
            }
        });

        if (! Schema::hasTable('product_option_items')) {
            Schema::create('product_option_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_product_id');
                $table->unsignedBigInteger('option_product_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->foreign('parent_product_id')
                    ->references('id')->on('product_services')->cascadeOnDelete();
                $table->foreign('option_product_id')
                    ->references('id')->on('product_services')->cascadeOnDelete();
                $table->unique(['parent_product_id', 'option_product_id'], 'poi_parent_option_unique');
                $table->index(['parent_product_id', 'sort_order'], 'poi_parent_sort_idx');
            });
        }

        $lineTables = [
            'pos_products',
            'invoice_products',
            'purchase_products',
            'bill_products',
            'proposal_products',
            'quotation_products',
        ];

        foreach ($lineTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'selected_options_json')) {
                    $table->text('selected_options_json')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        $lineTables = [
            'pos_products',
            'invoice_products',
            'purchase_products',
            'bill_products',
            'proposal_products',
            'quotation_products',
        ];

        foreach ($lineTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'selected_options_json')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('selected_options_json');
                });
            }
        }

        Schema::dropIfExists('product_option_items');

        Schema::table('product_services', function (Blueprint $table) {
            if (Schema::hasColumn('product_services', 'options_enabled')) {
                $table->dropColumn('options_enabled');
            }
        });
    }
};
