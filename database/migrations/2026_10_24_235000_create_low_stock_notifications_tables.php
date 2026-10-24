<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One ACTIVE (unresolved) low-stock alert per product + warehouse
        // per company. A new alert is created only after the previous one
        // resolved (stock recovered above the Low level) and falls again,
        // so repeated sales while stock stays low do not spam the bell.
        // Read status is per user in the companion table below.
        if (! Schema::hasTable('low_stock_notifications')) {
            Schema::create('low_stock_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->decimal('quantity', 12, 4)->default(0);
                $table->integer('low_stock_threshold')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'product_id', 'warehouse_id', 'resolved_at']);
            });
        }

        if (! Schema::hasTable('low_stock_notification_reads')) {
            Schema::create('low_stock_notification_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('notification_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->unique(['notification_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('low_stock_notification_reads');
        Schema::dropIfExists('low_stock_notifications');
    }
};
