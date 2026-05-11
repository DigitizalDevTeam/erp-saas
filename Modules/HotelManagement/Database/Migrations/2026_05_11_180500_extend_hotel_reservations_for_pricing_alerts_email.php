<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('hotel_rate_plan_id')->nullable()->after('hotel_room_id');
            $table->enum('billing_mode', ['nightly', 'monthly'])->default('nightly')->after('status');
            $table->time('estimated_arrival_time')->nullable()->after('check_out_at');
            $table->boolean('parking_requested')->default(false)->after('estimated_arrival_time');
            $table->text('discount_reason')->nullable()->after('discount_amount');
            $table->timestamp('guest_email_sent_at')->nullable()->after('discount_reason');

            $table->foreign('hotel_rate_plan_id')
                ->references('id')
                ->on('hotel_rate_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropForeign(['hotel_rate_plan_id']);
            $table->dropColumn([
                'hotel_rate_plan_id',
                'billing_mode',
                'estimated_arrival_time',
                'parking_requested',
                'discount_reason',
                'guest_email_sent_at',
            ]);
        });
    }
};
