<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos', 'parent_pos_id')) {
            Schema::table('pos', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_pos_id')->nullable()->after('table_id');
                $table->index('parent_pos_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos', 'parent_pos_id')) {
            Schema::table('pos', function (Blueprint $table) {
                $table->dropIndex(['parent_pos_id']);
                $table->dropColumn('parent_pos_id');
            });
        }
    }
};
