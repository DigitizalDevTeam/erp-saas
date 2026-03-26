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
        Schema::table('product_services', function (Blueprint $table) {
            $table->boolean('batch_tracking')->default(false)->after('expiry_alert_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->dropColumn('batch_tracking');
        });
    }
};
