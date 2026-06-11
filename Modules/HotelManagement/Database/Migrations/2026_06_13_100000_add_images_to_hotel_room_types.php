<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_room_types', function (Blueprint $table) {
            if (! Schema::hasColumn('hotel_room_types', 'cover_image_path')) {
                $table->string('cover_image_path', 500)->nullable()->after('description');
            }
            if (! Schema::hasColumn('hotel_room_types', 'gallery_images')) {
                $table->json('gallery_images')->nullable()->after('cover_image_path');
            }
        });

        if (Schema::hasColumn('hotel_room_types', 'nightly_rate') && Schema::hasColumn('hotel_room_types', 'base_price')) {
            DB::table('hotel_room_types')
                ->where('nightly_rate', '<=', 0)
                ->where('base_price', '>', 0)
                ->update(['nightly_rate' => DB::raw('base_price')]);
        }
    }

    public function down(): void
    {
        Schema::table('hotel_room_types', function (Blueprint $table) {
            foreach (['gallery_images', 'cover_image_path'] as $col) {
                if (Schema::hasColumn('hotel_room_types', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
