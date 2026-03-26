<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_identifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->unsignedBigInteger('pos_product_id')->nullable();

            $table->string('identifier_type', 10)->default('serial'); // serial|mac
            $table->string('identifier_value', 191);
            $table->string('status', 20)->default('in_stock'); // in_stock|sold|returned|void

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->dateTime('sold_at')->nullable();
            $table->date('warranty_end_date')->nullable();

            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();

            $table->index(['created_by', 'product_id']);
            $table->index(['created_by', 'warehouse_id']);
            $table->unique(['created_by', 'identifier_value'], 'uniq_creator_identifier_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_identifiers');
    }
};
