<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_products', 'identifiers')) {
                $table->text('identifiers')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            if (Schema::hasColumn('pos_products', 'identifiers')) {
                $table->dropColumn('identifiers');
            }
        });
    }
};
