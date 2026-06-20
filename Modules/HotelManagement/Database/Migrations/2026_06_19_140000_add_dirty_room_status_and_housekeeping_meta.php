<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hotel_rooms') && Schema::hasColumn('hotel_rooms', 'status')) {
            DB::statement(
                "ALTER TABLE hotel_rooms MODIFY COLUMN status ENUM('available','occupied','dirty','cleaning','maintenance') NOT NULL DEFAULT 'available'"
            );
        }

        if (Schema::hasTable('hotel_housekeeping_tasks')) {
            Schema::table('hotel_housekeeping_tasks', function (Blueprint $table) {
                if (! Schema::hasColumn('hotel_housekeeping_tasks', 'source')) {
                    $table->string('source', 32)->nullable()->after('status');
                }
                if (! Schema::hasColumn('hotel_housekeeping_tasks', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('hotel_housekeeping_tasks', 'completed_by')) {
                    $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_rooms') && Schema::hasColumn('hotel_rooms', 'status')) {
            DB::table('hotel_rooms')->where('status', 'dirty')->update(['status' => 'cleaning']);

            DB::statement(
                "ALTER TABLE hotel_rooms MODIFY COLUMN status ENUM('available','occupied','cleaning','maintenance') NOT NULL DEFAULT 'available'"
            );
        }

        if (Schema::hasTable('hotel_housekeeping_tasks')) {
            Schema::table('hotel_housekeeping_tasks', function (Blueprint $table) {
                $cols = ['completed_by', 'completed_at', 'source'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('hotel_housekeeping_tasks', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
