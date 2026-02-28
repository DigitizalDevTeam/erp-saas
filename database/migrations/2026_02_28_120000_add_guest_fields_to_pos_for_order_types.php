<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add guest name, email, phone for delivery/takeaway orders (no table).
     */
    public function up(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->string('guest_name', 255)->nullable()->after('customer_id');
            $table->string('guest_email', 255)->nullable()->after('guest_name');
            $table->string('guest_phone', 64)->nullable()->after('guest_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);
        });
    }
};
