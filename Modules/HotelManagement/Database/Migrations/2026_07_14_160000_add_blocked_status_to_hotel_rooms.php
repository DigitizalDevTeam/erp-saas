<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_rooms')) {
            return;
        }

        DB::statement(
            "ALTER TABLE hotel_rooms MODIFY COLUMN status ENUM('available','occupied','dirty','cleaning','maintenance','blocked') NOT NULL DEFAULT 'available'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('hotel_rooms')) {
            return;
        }

        DB::table('hotel_rooms')->where('status', 'blocked')->update(['status' => 'maintenance']);

        DB::statement(
            "ALTER TABLE hotel_rooms MODIFY COLUMN status ENUM('available','occupied','dirty','cleaning','maintenance') NOT NULL DEFAULT 'available'"
        );
    }
};
