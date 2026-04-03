<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRowShelfToWarehouseProductsAndPurchaseProducts extends Migration
{
    public function up()
    {
        Schema::table('warehouse_products', function (Blueprint $table) {
            $table->string('row_no', 64)->default('')->after('quantity');
            $table->string('shelf_no', 64)->default('')->after('row_no');
        });

        Schema::table('purchase_products', function (Blueprint $table) {
            $table->string('row_no', 64)->nullable()->after('description');
            $table->string('shelf_no', 64)->nullable()->after('row_no');
        });
    }

    public function down()
    {
        Schema::table('warehouse_products', function (Blueprint $table) {
            $table->dropColumn(['row_no', 'shelf_no']);
        });

        Schema::table('purchase_products', function (Blueprint $table) {
            $table->dropColumn(['row_no', 'shelf_no']);
        });
    }
}
