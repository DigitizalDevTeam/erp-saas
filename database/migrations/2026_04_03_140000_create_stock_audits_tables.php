<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('period_type', 20)->default('all');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'created_at']);
        });

        Schema::create('stock_audit_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_audit_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name')->nullable();
            $table->string('sku', 191)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('row_no', 100)->nullable();
            $table->string('shelf_no', 100)->nullable();
            $table->decimal('system_quantity', 16, 4)->default(0);
            $table->decimal('physical_quantity', 16, 4)->nullable();
            $table->decimal('difference', 16, 4)->nullable();
            $table->timestamps();

            $table->foreign('stock_audit_id')->references('id')->on('stock_audits')->onDelete('cascade');
            $table->index(['stock_audit_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_audit_lines');
        Schema::dropIfExists('stock_audits');
    }
};
