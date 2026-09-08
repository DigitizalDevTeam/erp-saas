<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_rate_plans')) {
            return;
        }

        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_rate_plans', 'plan_type')) {
                $table->string('plan_type', 32)->default('period')->after('name');
            }
        });

        DB::table('hotel_rate_plans')
            ->where(function ($q) {
                $q->whereNull('plan_type')->orWhere('plan_type', '');
            })
            ->update(['plan_type' => 'period']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE hotel_rate_plans MODIFY valid_from DATETIME NULL');
            DB::statement('ALTER TABLE hotel_rate_plans MODIFY valid_to DATETIME NULL');

            // Preserve old date-only rows as full-day windows.
            DB::table('hotel_rate_plans')->whereNotNull('valid_from')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $from = (string) $row->valid_from;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || str_ends_with($from, '00:00:00')) {
                        $date = substr($from, 0, 10);
                        DB::table('hotel_rate_plans')->where('id', $row->id)->update([
                            'valid_from' => $date . ' 00:00:00',
                        ]);
                    }
                }
            });

            DB::table('hotel_rate_plans')->whereNotNull('valid_to')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $to = (string) $row->valid_to;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || str_ends_with($to, '00:00:00')) {
                        $date = substr($to, 0, 10);
                        DB::table('hotel_rate_plans')->where('id', $row->id)->update([
                            'valid_to' => $date . ' 23:59:59',
                        ]);
                    }
                }
            });
        } else {
            DB::table('hotel_rate_plans')->whereNotNull('valid_to')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $to = (string) $row->valid_to;
                    if (strlen($to) <= 10 || str_ends_with($to, '00:00:00')) {
                        $date = substr($to, 0, 10);
                        DB::table('hotel_rate_plans')->where('id', $row->id)->update([
                            'valid_to' => $date . ' 23:59:59',
                        ]);
                    }
                }
            });
        }

        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            $table->index(['created_by', 'hotel_room_type_id', 'plan_type', 'is_active'], 'hotel_rate_plans_lookup_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hotel_rate_plans')) {
            return;
        }

        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            $table->dropIndex('hotel_rate_plans_lookup_idx');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE hotel_rate_plans MODIFY valid_from DATE NULL');
            DB::statement('ALTER TABLE hotel_rate_plans MODIFY valid_to DATE NULL');
        }

        Schema::table('hotel_rate_plans', function (Blueprint $table) {
            if (Schema::hasColumn('hotel_rate_plans', 'plan_type')) {
                $table->dropColumn('plan_type');
            }
        });
    }
};
