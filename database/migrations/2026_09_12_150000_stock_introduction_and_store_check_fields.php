<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_introductions')) {
            Schema::create('stock_introductions', function (Blueprint $table) {
                $table->id();
                $table->string('intro_no', 64)->nullable();
                $table->unsignedBigInteger('warehouse_id')->default(0);
                $table->date('intro_date')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->default(0);
                $table->timestamps();
                $table->index(['created_by', 'intro_date']);
            });
        }

        if (! Schema::hasTable('stock_introduction_lines')) {
            Schema::create('stock_introduction_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('stock_introduction_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('quantity', 18, 4)->default(0);
                $table->string('row_no', 64)->nullable();
                $table->string('shelf_no', 64)->nullable();
                $table->string('batch_no', 100)->nullable();
                $table->date('expiry_date')->nullable();
                $table->date('mfg_date')->nullable();
                $table->timestamps();

                $table->foreign('stock_introduction_id')->references('id')->on('stock_introductions')->onDelete('cascade');
                $table->index(['stock_introduction_id', 'product_id']);
            });
        }

        Schema::table('stock_audits', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_audits', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('stock_audits', 'check_date')) {
                $table->date('check_date')->nullable()->after('warehouse_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            if (Schema::hasColumn('stock_audits', 'check_date')) {
                $table->dropColumn('check_date');
            }
            if (Schema::hasColumn('stock_audits', 'warehouse_id')) {
                $table->dropColumn('warehouse_id');
            }
        });

        Schema::dropIfExists('stock_introduction_lines');
        Schema::dropIfExists('stock_introductions');
    }
};
