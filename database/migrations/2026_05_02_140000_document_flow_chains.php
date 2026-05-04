<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('proformas')) {
            Schema::create('proformas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('proforma_id')->default(0);
                $table->unsignedBigInteger('quotation_id')->nullable();
                $table->unsignedBigInteger('customer_id')->default(0);
                $table->integer('warehouse_id')->default(0);
                $table->date('proforma_date')->nullable();
                $table->integer('category_id')->default(0);
                $table->integer('status')->default(0);
                $table->unsignedBigInteger('converted_invoice_id')->nullable();
                $table->tinyInteger('is_invoice_converted')->default(0);
                $table->integer('created_by')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('proforma_products')) {
            Schema::create('proforma_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('proforma_id')->default(0);
                $table->unsignedBigInteger('product_id')->default(0);
                $table->decimal('quantity', 18, 4)->default(0);
                $table->string('tax')->default('0')->nullable();
                $table->decimal('discount', 15, 2)->default(0)->nullable();
                $table->decimal('price', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_quotations')) {
            Schema::create('purchase_quotations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('purchase_quotation_id')->default(0);
                $table->integer('vender_id')->default(0);
                $table->integer('warehouse_id')->default(0);
                $table->date('quote_date')->nullable();
                $table->integer('category_id')->default(0);
                $table->integer('status')->default(0);
                $table->unsignedBigInteger('converted_purchase_id')->nullable();
                $table->tinyInteger('is_converted')->default(0);
                $table->integer('created_by')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_quotation_products')) {
            Schema::create('purchase_quotation_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_quotation_id')->default(0);
                $table->unsignedBigInteger('product_id')->default(0);
                $table->decimal('quantity', 18, 4)->default(0);
                $table->string('tax')->default('0')->nullable();
                $table->decimal('discount', 15, 2)->default(0)->nullable();
                $table->decimal('price', 15, 2)->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'converted_proforma_id')) {
                $table->unsignedBigInteger('converted_proforma_id')->nullable()->after('is_converted');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'quotation_id')) {
                $table->unsignedBigInteger('quotation_id')->nullable()->after('pos_id');
            }
            if (! Schema::hasColumn('invoices', 'proforma_id')) {
                $table->unsignedBigInteger('proforma_id')->nullable()->after('quotation_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'purchase_quotation_id')) {
                $table->unsignedBigInteger('purchase_quotation_id')->nullable()->after('purchase_id');
            }
            if (! Schema::hasColumn('purchases', 'converted_bill_id')) {
                $table->unsignedBigInteger('converted_bill_id')->nullable()->after('purchase_quotation_id');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'purchase_id')) {
                $table->unsignedBigInteger('purchase_id')->nullable()->after('order_number');
            }
            if (! Schema::hasColumn('bills', 'suppress_inventory')) {
                $table->boolean('suppress_inventory')->default(false)->after('purchase_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'suppress_inventory')) {
                $table->dropColumn('suppress_inventory');
            }
            if (Schema::hasColumn('bills', 'purchase_id')) {
                $table->dropColumn('purchase_id');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'converted_bill_id')) {
                $table->dropColumn('converted_bill_id');
            }
            if (Schema::hasColumn('purchases', 'purchase_quotation_id')) {
                $table->dropColumn('purchase_quotation_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'proforma_id')) {
                $table->dropColumn('proforma_id');
            }
            if (Schema::hasColumn('invoices', 'quotation_id')) {
                $table->dropColumn('quotation_id');
            }
        });

        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'converted_proforma_id')) {
                $table->dropColumn('converted_proforma_id');
            }
        });

        Schema::dropIfExists('purchase_quotation_products');
        Schema::dropIfExists('purchase_quotations');
        Schema::dropIfExists('proforma_products');
        Schema::dropIfExists('proformas');
    }
};
