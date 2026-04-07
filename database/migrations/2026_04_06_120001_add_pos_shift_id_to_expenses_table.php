<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'pos_shift_id')) {
                $table->unsignedBigInteger('pos_shift_id')->nullable()->after('branch_id');
                $table->index(['pos_shift_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'pos_shift_id')) {
                $table->dropIndex(['pos_shift_id']);
                $table->dropColumn('pos_shift_id');
            }
        });
    }
};

