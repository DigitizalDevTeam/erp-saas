<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hotel_reservations')) {
            Schema::table('hotel_reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('hotel_reservations', 'meal_plan')) {
                    $table->string('meal_plan', 32)->nullable()->after('billing_mode');
                }
                if (! Schema::hasColumn('hotel_reservations', 'booking_guarantee')) {
                    $table->string('booking_guarantee', 32)->nullable()->after('meal_plan');
                }
                if (! Schema::hasColumn('hotel_reservations', 'payment_method')) {
                    $table->string('payment_method', 50)->nullable()->after('booking_guarantee');
                }
                if (! Schema::hasColumn('hotel_reservations', 'infants')) {
                    $table->unsignedTinyInteger('infants')->default(0)->after('children');
                }
                if (! Schema::hasColumn('hotel_reservations', 'discount_percent')) {
                    $table->decimal('discount_percent', 8, 2)->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('hotel_reservations', 'special_requests')) {
                    $table->json('special_requests')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('hotel_reservations', 'internal_notes')) {
                    $table->text('internal_notes')->nullable()->after('special_requests');
                }
            });
        }

        if (! Schema::hasTable('hotel_cashier_shifts')) {
            Schema::create('hotel_cashier_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('operational_shift', 32)->nullable();
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->decimal('opening_float', 15, 2)->default(0);
                $table->decimal('closing_cash', 15, 2)->nullable();
                $table->decimal('expected_cash', 15, 2)->nullable();
                $table->decimal('cash_collected', 15, 2)->default(0);
                $table->decimal('card_collected', 15, 2)->default(0);
                $table->decimal('other_collected', 15, 2)->default(0);
                $table->decimal('difference', 15, 2)->nullable();
                $table->decimal('cash_handover', 15, 2)->nullable();
                $table->string('handed_to', 191)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('open')->index();
                $table->unsignedBigInteger('created_by')->index();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hotel_expenses')) {
            Schema::create('hotel_expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('category', 64)->index();
                $table->string('title');
                $table->decimal('amount', 15, 2);
                $table->date('expense_date');
                $table->string('payment_method', 50)->nullable();
                $table->string('vendor', 191)->nullable();
                $table->string('reference', 191)->nullable();
                $table->unsignedBigInteger('hotel_reservation_id')->nullable()->index();
                $table->unsignedBigInteger('hotel_room_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->index();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('hotel_folio_payments') && ! Schema::hasColumn('hotel_folio_payments', 'recorded_by')) {
            Schema::table('hotel_folio_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('recorded_by')->nullable()->after('created_by')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_expenses');
        Schema::dropIfExists('hotel_cashier_shifts');

        if (Schema::hasTable('hotel_reservations')) {
            Schema::table('hotel_reservations', function (Blueprint $table) {
                foreach (['meal_plan', 'booking_guarantee', 'payment_method', 'infants', 'discount_percent', 'special_requests', 'internal_notes'] as $col) {
                    if (Schema::hasColumn('hotel_reservations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
