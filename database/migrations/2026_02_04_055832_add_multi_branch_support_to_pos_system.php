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
        // Extend branches table with address, timezone, is_active
        Schema::table('branches', function (Blueprint $table) {
            $table->text('address')->nullable()->after('name');
            $table->string('timezone', 50)->default('UTC')->after('address');
            $table->boolean('is_active')->default(true)->after('timezone');
        });

        // Add branch_id to warehouses
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('id');
        });

        // Add branch_id to pos
        Schema::table('pos', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('id');
        });

        // Add branch_id to pos_payments (denormalized for reporting; pos has branch_id)
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('id');
        });

        // Add branch_id to pos_balances if table exists
        if (Schema::hasTable('pos_balances')) {
            Schema::table('pos_balances', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['address', 'timezone', 'is_active']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        Schema::table('pos', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });

        if (Schema::hasTable('pos_balances')) {
            Schema::table('pos_balances', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }
};
