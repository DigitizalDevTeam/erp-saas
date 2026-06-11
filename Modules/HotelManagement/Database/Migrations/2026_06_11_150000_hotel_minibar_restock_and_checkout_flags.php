<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hotel_minibar_items') && ! Schema::hasColumn('hotel_minibar_items', 'default_quantity')) {
            Schema::table('hotel_minibar_items', function (Blueprint $table) {
                $table->unsignedSmallInteger('default_quantity')->default(1)->after('unit_price');
            });
        }

        if (Schema::hasTable('hotel_stays') && ! Schema::hasColumn('hotel_stays', 'minibar_verified_at')) {
            Schema::table('hotel_stays', function (Blueprint $table) {
                $table->timestamp('minibar_verified_at')->nullable()->after('notes');
            });
        }

        if (Schema::hasTable('hotel_accounting_settings') && ! Schema::hasColumn('hotel_accounting_settings', 'require_minibar_scan_at_checkout')) {
            Schema::table('hotel_accounting_settings', function (Blueprint $table) {
                $table->boolean('require_minibar_scan_at_checkout')->default(false)->after('pos_invoice_when_charge_to_room');
            });
        }

        if (! Schema::hasTable('hotel_room_minibar_restocks')) {
            Schema::create('hotel_room_minibar_restocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hotel_room_id');
                $table->unsignedBigInteger('hotel_stay_id')->nullable();
                $table->string('status', 32)->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['created_by', 'status'], 'hotel_mb_restock_creator_status_idx');
                $table->index('hotel_room_id', 'hotel_mb_restock_room_idx');
            });
        }

        if (! Schema::hasTable('hotel_room_minibar_restock_lines')) {
            Schema::create('hotel_room_minibar_restock_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hotel_room_minibar_restock_id');
                $table->unsignedBigInteger('hotel_minibar_item_id');
                $table->unsignedSmallInteger('expected_qty')->default(1);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index('hotel_room_minibar_restock_id', 'hotel_mb_restock_line_parent_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_room_minibar_restock_lines');
        Schema::dropIfExists('hotel_room_minibar_restocks');

        if (Schema::hasTable('hotel_accounting_settings') && Schema::hasColumn('hotel_accounting_settings', 'require_minibar_scan_at_checkout')) {
            Schema::table('hotel_accounting_settings', function (Blueprint $table) {
                $table->dropColumn('require_minibar_scan_at_checkout');
            });
        }

        if (Schema::hasTable('hotel_stays') && Schema::hasColumn('hotel_stays', 'minibar_verified_at')) {
            Schema::table('hotel_stays', function (Blueprint $table) {
                $table->dropColumn('minibar_verified_at');
            });
        }

        if (Schema::hasTable('hotel_minibar_items') && Schema::hasColumn('hotel_minibar_items', 'default_quantity')) {
            Schema::table('hotel_minibar_items', function (Blueprint $table) {
                $table->dropColumn('default_quantity');
            });
        }
    }
};
