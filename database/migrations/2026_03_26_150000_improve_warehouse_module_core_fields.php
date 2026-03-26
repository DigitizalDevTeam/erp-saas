<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('pos_branch_id');
            }
        });

        Schema::table('product_services', function (Blueprint $table) {
            if (!Schema::hasColumn('product_services', 'shelf_no')) {
                $table->string('shelf_no', 100)->nullable()->after('sku');
            }
            if (!Schema::hasColumn('product_services', 'row_no')) {
                $table->string('row_no', 100)->nullable()->after('shelf_no');
            }
        });

        if (!Schema::hasTable('warehouse_documents')) {
            Schema::create('warehouse_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id');
                $table->string('title')->nullable();
                $table->string('file_path');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['warehouse_id', 'created_by']);
            });
        }

        if (!Schema::hasTable('warehouse_losses')) {
            Schema::create('warehouse_losses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('quantity', 15, 4);
                $table->date('date')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['warehouse_id', 'product_id']);
                $table->index(['created_by', 'date']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warehouse_losses')) {
            Schema::drop('warehouse_losses');
        }
        if (Schema::hasTable('warehouse_documents')) {
            Schema::drop('warehouse_documents');
        }

        Schema::table('product_services', function (Blueprint $table) {
            if (Schema::hasColumn('product_services', 'row_no')) {
                $table->dropColumn('row_no');
            }
            if (Schema::hasColumn('product_services', 'shelf_no')) {
                $table->dropColumn('shelf_no');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'warehouse_id')) {
                $table->dropColumn('warehouse_id');
            }
        });
    }
};

