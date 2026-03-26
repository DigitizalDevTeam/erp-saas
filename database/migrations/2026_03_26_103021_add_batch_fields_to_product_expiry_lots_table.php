<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_expiry_lots', function (Blueprint $table) {
            $table->string('batch_no', 80)->nullable()->after('source');
            $table->date('mfg_date')->nullable()->after('batch_no');

            $table->index(['created_by', 'batch_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_expiry_lots', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'batch_no']);
            $table->dropColumn(['batch_no', 'mfg_date']);
        });
    }
};
