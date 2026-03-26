<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            if (!Schema::hasColumn('product_services', 'max_stock_threshold')) {
                $table->unsignedInteger('max_stock_threshold')->nullable()->after('low_stock_threshold');
            }
            if (!Schema::hasColumn('product_services', 'last_min_stock_alert_at')) {
                $table->timestamp('last_min_stock_alert_at')->nullable()->after('max_stock_threshold');
            }
            if (!Schema::hasColumn('product_services', 'last_max_stock_alert_at')) {
                $table->timestamp('last_max_stock_alert_at')->nullable()->after('last_min_stock_alert_at');
            }
        });

        if (!Schema::hasTable('road_transport_records')) {
            Schema::create('road_transport_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('road_manifest_id')->nullable();
                $table->string('record_no')->unique();
                $table->date('transport_date');
                $table->string('vehicle_no')->nullable();
                $table->string('driver_name')->nullable();
                $table->string('from_location')->nullable();
                $table->string('to_location')->nullable();
                $table->timestamp('departure_at')->nullable();
                $table->timestamp('arrival_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->index(['created_by', 'transport_date']);
                $table->index(['road_manifest_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('road_transport_records')) {
            Schema::drop('road_transport_records');
        }

        Schema::table('product_services', function (Blueprint $table) {
            if (Schema::hasColumn('product_services', 'last_max_stock_alert_at')) {
                $table->dropColumn('last_max_stock_alert_at');
            }
            if (Schema::hasColumn('product_services', 'last_min_stock_alert_at')) {
                $table->dropColumn('last_min_stock_alert_at');
            }
            if (Schema::hasColumn('product_services', 'max_stock_threshold')) {
                $table->dropColumn('max_stock_threshold');
            }
        });
    }
};

