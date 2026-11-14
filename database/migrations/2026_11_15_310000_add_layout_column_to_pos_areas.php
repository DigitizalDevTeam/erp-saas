<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The AREA IS THE FLOOR: one floor design per POS area, stored as
        // JSON on the area itself — no separate layout table or entity.
        // Shape: {version: 1, elements: [{type, table_id?, x, y, w, h, rot, label}]}
        // Table elements reference real pos_tables rows by id; decoration
        // elements (wall, door, bar, plant, ...) carry no table_id.
        Schema::table('pos_areas', function (Blueprint $table) {
            $table->json('layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_areas', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
