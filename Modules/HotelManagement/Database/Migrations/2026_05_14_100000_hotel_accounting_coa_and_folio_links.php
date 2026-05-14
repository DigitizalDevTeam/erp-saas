<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('chart_guest_ar_id')->nullable()->comment('Accounts receivable — guest');
            $table->unsignedBigInteger('chart_room_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_restaurant_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_laundry_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_misc_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_vat_payable_id')->nullable();
            $table->decimal('default_vat_rate_percent', 5, 2)->default(16);
            $table->boolean('pos_invoice_when_charge_to_room')->default(false);
            $table->json('folio_payment_bank_map')->nullable();
            $table->timestamps();

            $table->unique('created_by');
        });

        if (Schema::hasTable('hotel_folios')) {
            Schema::table('hotel_folios', function (Blueprint $table) {
                $table->unsignedBigInteger('finalize_journal_id')->nullable()->after('status');
                $table->unsignedBigInteger('invoice_id')->nullable()->after('finalize_journal_id');
            });
        }

        if (Schema::hasTable('hotel_folio_lines')) {
            Schema::table('hotel_folio_lines', function (Blueprint $table) {
                $table->string('revenue_channel', 32)->nullable()->after('line_type');
            });
        }

        if (Schema::hasTable('hotel_folio_payments')) {
            Schema::table('hotel_folio_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('accounting_payment_journal_id')->nullable()->after('notes');
            });
        }

        if (Schema::hasTable('hotel_guests')) {
            Schema::table('hotel_guests', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('created_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_guests')) {
            Schema::table('hotel_guests', function (Blueprint $table) {
                $table->dropColumn('customer_id');
            });
        }
        if (Schema::hasTable('hotel_folio_payments')) {
            Schema::table('hotel_folio_payments', function (Blueprint $table) {
                $table->dropColumn('accounting_payment_journal_id');
            });
        }
        if (Schema::hasTable('hotel_folio_lines')) {
            Schema::table('hotel_folio_lines', function (Blueprint $table) {
                $table->dropColumn('revenue_channel');
            });
        }
        if (Schema::hasTable('hotel_folios')) {
            Schema::table('hotel_folios', function (Blueprint $table) {
                $table->dropColumn(['finalize_journal_id', 'invoice_id']);
            });
        }
        Schema::dropIfExists('hotel_accounting_settings');
    }
};
