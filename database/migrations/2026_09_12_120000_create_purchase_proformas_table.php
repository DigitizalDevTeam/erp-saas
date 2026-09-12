<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_proformas')) {
            Schema::create('purchase_proformas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('purchase_proforma_id')->default(0);
                $table->integer('vender_id')->default(0);
                $table->integer('warehouse_id')->default(0);
                $table->date('proforma_date')->nullable();
                $table->integer('category_id')->default(0);
                $table->integer('status')->default(0);
                $table->unsignedBigInteger('purchase_quotation_id')->nullable();
                $table->unsignedBigInteger('converted_purchase_id')->nullable();
                $table->tinyInteger('is_converted')->default(0);
                $table->integer('created_by')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_proforma_products')) {
            Schema::create('purchase_proforma_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_proforma_id')->default(0);
                $table->unsignedBigInteger('product_id')->default(0);
                $table->decimal('quantity', 18, 4)->default(0);
                $table->string('tax')->default('0')->nullable();
                $table->decimal('discount', 15, 2)->default(0)->nullable();
                $table->decimal('price', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'purchase_proforma_id')) {
                $table->unsignedBigInteger('purchase_proforma_id')->nullable()->after('purchase_quotation_id');
            }
        });

        Schema::table('purchase_quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_quotations', 'converted_purchase_proforma_id')) {
                $table->unsignedBigInteger('converted_purchase_proforma_id')->nullable()->after('converted_proforma_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_quotations', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_quotations', 'converted_purchase_proforma_id')) {
                $table->dropColumn('converted_purchase_proforma_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'purchase_proforma_id')) {
                $table->dropColumn('purchase_proforma_id');
            }
        });

        Schema::dropIfExists('purchase_proforma_products');
        Schema::dropIfExists('purchase_proformas');
    }
};
