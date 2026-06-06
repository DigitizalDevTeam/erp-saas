<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_void_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->boolean('stock_restored')->default(false);
            $table->boolean('accounting_note_created')->default(false);
            $table->unsignedBigInteger('accounting_note_id')->nullable();
            $table->string('accounting_note_type', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'document_type']);
            $table->index(['document_type', 'document_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('stock_deducted_via');
            }
            if (! Schema::hasColumn('invoices', 'voided_by')) {
                $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('invoices', 'void_reason')) {
                $table->string('void_reason', 500)->nullable()->after('voided_by');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('bills', 'voided_by')) {
                $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('bills', 'void_reason')) {
                $table->string('void_reason', 500)->nullable()->after('voided_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'void_reason')) {
                $table->dropColumn('void_reason');
            }
            if (Schema::hasColumn('bills', 'voided_by')) {
                $table->dropColumn('voided_by');
            }
            if (Schema::hasColumn('bills', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'void_reason')) {
                $table->dropColumn('void_reason');
            }
            if (Schema::hasColumn('invoices', 'voided_by')) {
                $table->dropColumn('voided_by');
            }
            if (Schema::hasColumn('invoices', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
        });

        Schema::dropIfExists('document_void_logs');
    }
};
