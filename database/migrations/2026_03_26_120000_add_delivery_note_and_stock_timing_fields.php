<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'stock_deducted_at')) {
                $table->timestamp('stock_deducted_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoices', 'stock_deducted_via')) {
                $table->string('stock_deducted_via', 32)->nullable()->after('stock_deducted_at');
            }
        });

        Schema::table('invoice_products', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_products', 'identifiers')) {
                $table->text('identifiers')->nullable()->after('description');
            }
        });

        if (!Schema::hasTable('delivery_notes')) {
            Schema::create('delivery_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->unique();
                $table->string('delivery_note_no')->unique();
                $table->date('issue_date');
                $table->unsignedTinyInteger('status')->default(0); // 0=draft, 1=issued
                $table->timestamp('stock_deducted_at')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['created_by', 'issue_date']);
                $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('road_manifests')) {
            Schema::create('road_manifests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delivery_note_id')->unique();
                $table->string('manifest_no')->unique();
                $table->string('vehicle_no')->nullable();
                $table->string('driver_name')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedTinyInteger('status')->default(0); // 0=draft, 1=dispatched
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['created_by', 'created_at']);
                $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('road_manifests')) {
            Schema::drop('road_manifests');
        }
        if (Schema::hasTable('delivery_notes')) {
            Schema::drop('delivery_notes');
        }

        Schema::table('invoice_products', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_products', 'identifiers')) {
                $table->dropColumn('identifiers');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'stock_deducted_via')) {
                $table->dropColumn('stock_deducted_via');
            }
            if (Schema::hasColumn('invoices', 'stock_deducted_at')) {
                $table->dropColumn('stock_deducted_at');
            }
        });
    }
};

