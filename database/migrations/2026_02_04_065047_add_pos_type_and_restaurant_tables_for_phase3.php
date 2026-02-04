<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 3: POS type (retail/restaurant), areas, tables, shifts, waiter assignments.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('pos_type', 20)->default('retail')->after('is_active'); // retail | restaurant
        });

        Schema::table('pos', function (Blueprint $table) {
            $table->string('order_type', 20)->nullable()->after('branch_id'); // dine_in | takeaway | delivery | kiosk
            $table->unsignedBigInteger('table_id')->nullable()->after('order_type');
            $table->unsignedBigInteger('shift_id')->nullable()->after('table_id');
        });

        Schema::create('pos_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('status', 20)->default('available'); // available | occupied | reserved
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id'); // waiter/cashier
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->decimal('closing_balance', 16, 2)->nullable();
            $table->string('status', 20)->default('open'); // open | closed
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_table_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('table_id');
            $table->unsignedBigInteger('user_id'); // waiter
            $table->dateTime('assigned_at');
            $table->dateTime('unassigned_at')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('pos_type');
        });

        Schema::table('pos', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'table_id', 'shift_id']);
        });

        Schema::dropIfExists('pos_table_assignments');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('pos_tables');
        Schema::dropIfExists('pos_areas');
    }
};
