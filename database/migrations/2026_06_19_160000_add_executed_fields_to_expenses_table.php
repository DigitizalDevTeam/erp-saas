<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'executed_at')) {
                $table->timestamp('executed_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('expenses', 'executed_by')) {
                $table->unsignedBigInteger('executed_by')->nullable()->after('executed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'executed_by')) {
                $table->dropColumn('executed_by');
            }
            if (Schema::hasColumn('expenses', 'executed_at')) {
                $table->dropColumn('executed_at');
            }
        });
    }
};
