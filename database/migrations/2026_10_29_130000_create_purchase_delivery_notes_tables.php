<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marker: 1 = stock for this document comes ONLY from delivery
        // notes. 0 / missing column = legacy document that already added
        // its stock at create time. Never flip a legacy row to 1.
        if (Schema::hasTable('purchases') && ! Schema::hasColumn('purchases', 'stock_on_delivery')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->tinyInteger('stock_on_delivery')->default(0)->after('status');
            });
        }

        if (Schema::hasTable('bills') && ! Schema::hasColumn('bills', 'stock_on_delivery')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->tinyInteger('stock_on_delivery')->default(0)->after('status');
            });
        }

        if (! Schema::hasTable('purchase_delivery_notes')) {
            Schema::create('purchase_delivery_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('note_number')->default(0);
                $table->unsignedBigInteger('vender_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('purchase_id')->nullable();
                $table->unsignedBigInteger('bill_id')->nullable();
                $table->date('delivery_date');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['created_by', 'delivery_date'], 'pdn_creator_date');
                $table->index(['purchase_id'], 'pdn_purchase_idx');
                $table->index(['bill_id'], 'pdn_bill_idx');
            });
        }

        if (! Schema::hasTable('purchase_delivery_note_items')) {
            Schema::create('purchase_delivery_note_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_delivery_note_id');
                $table->string('source_type', 20);
                $table->unsignedBigInteger('source_line_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                // Decimal on purpose: legacy integer quantity columns round
                // 75.5 to 76 elsewhere. A delivery quantity must be exact.
                $table->decimal('ordered_qty', 18, 4)->default(0);
                $table->decimal('delivered_qty', 18, 4)->default(0);
                $table->decimal('price', 15, 2)->default(0);
                $table->string('row_no')->nullable();
                $table->string('shelf_no')->nullable();
                $table->string('batch_no')->nullable();
                $table->date('expiry_date')->nullable();
                $table->date('mfg_date')->nullable();
                $table->text('selected_options_json')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['purchase_delivery_note_id'], 'pdni_note_idx');
                $table->index(['source_type', 'source_line_id'], 'pdni_source_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_delivery_note_items');
        Schema::dropIfExists('purchase_delivery_notes');

        if (Schema::hasTable('bills') && Schema::hasColumn('bills', 'stock_on_delivery')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('stock_on_delivery');
            });
        }

        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'stock_on_delivery')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropColumn('stock_on_delivery');
            });
        }
    }
};
