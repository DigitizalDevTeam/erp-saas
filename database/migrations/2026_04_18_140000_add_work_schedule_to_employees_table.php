<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'attendance_use_company_default')) {
                $table->boolean('attendance_use_company_default')->default(true)->after('salary');
            }
            if (! Schema::hasColumn('employees', 'work_schedule_type')) {
                $table->string('work_schedule_type', 32)->default('office_fixed')->after('attendance_use_company_default');
            }
            if (! Schema::hasColumn('employees', 'work_start_time')) {
                $table->time('work_start_time')->nullable()->after('work_schedule_type');
            }
            if (! Schema::hasColumn('employees', 'work_end_time')) {
                $table->time('work_end_time')->nullable()->after('work_start_time');
            }
            if (! Schema::hasColumn('employees', 'grace_minutes')) {
                $table->unsignedSmallInteger('grace_minutes')->default(0)->after('work_end_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach (['grace_minutes', 'work_end_time', 'work_start_time', 'work_schedule_type', 'attendance_use_company_default'] as $col) {
                if (Schema::hasColumn('employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
