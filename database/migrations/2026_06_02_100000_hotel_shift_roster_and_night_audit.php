<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_weekly_shifts')) {
            Schema::create('employee_weekly_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedTinyInteger('day_of_week'); // 1=Mon … 7=Sun
                $table->string('shift_code', 20)->default('off'); // off, morning, afternoon, night, custom
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->boolean('crosses_midnight')->default(false);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();
                $table->unique(['employee_id', 'day_of_week']);
            });
        }

        if (Schema::hasTable('pos_shifts') && ! Schema::hasColumn('pos_shifts', 'operational_shift')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                $table->string('operational_shift', 20)->nullable()->after('status');
            });
        }

        if (Schema::hasTable('hotel_night_audits')) {
            Schema::table('hotel_night_audits', function (Blueprint $table) {
                if (! Schema::hasColumn('hotel_night_audits', 'pos_sales_total')) {
                    $table->decimal('pos_sales_total', 16, 2)->default(0)->after('total_revenue');
                }
                if (! Schema::hasColumn('hotel_night_audits', 'pos_payments_total')) {
                    $table->decimal('pos_payments_total', 16, 2)->default(0)->after('pos_sales_total');
                }
                if (! Schema::hasColumn('hotel_night_audits', 'summary_json')) {
                    $table->json('summary_json')->nullable()->after('pos_payments_total');
                }
                if (! Schema::hasColumn('hotel_night_audits', 'closed_by')) {
                    $table->unsignedBigInteger('closed_by')->nullable()->after('created_by');
                }
            });
        }

        if (Schema::hasTable('pos_day_closed') && ! Schema::hasColumn('pos_day_closed', 'locked_by_night_audit')) {
            Schema::table('pos_day_closed', function (Blueprint $table) {
                $table->boolean('locked_by_night_audit')->default(false)->after('created_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_weekly_shifts')) {
            Schema::dropIfExists('employee_weekly_shifts');
        }
        if (Schema::hasTable('pos_shifts') && Schema::hasColumn('pos_shifts', 'operational_shift')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                $table->dropColumn('operational_shift');
            });
        }
        if (Schema::hasTable('hotel_night_audits')) {
            Schema::table('hotel_night_audits', function (Blueprint $table) {
                foreach (['closed_by', 'summary_json', 'pos_payments_total', 'pos_sales_total'] as $col) {
                    if (Schema::hasColumn('hotel_night_audits', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::hasTable('pos_day_closed') && Schema::hasColumn('pos_day_closed', 'locked_by_night_audit')) {
            Schema::table('pos_day_closed', function (Blueprint $table) {
                $table->dropColumn('locked_by_night_audit');
            });
        }
    }
};
