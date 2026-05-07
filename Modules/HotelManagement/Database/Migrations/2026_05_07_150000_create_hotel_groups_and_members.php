<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('hotel_group_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_group_id');
            $table->unsignedBigInteger('hotel_guest_id');
            $table->string('role', 20)->default('adult');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('hotel_group_id')->references('id')->on('hotel_groups')->onDelete('cascade');
            $table->foreign('hotel_guest_id')->references('id')->on('hotel_guests')->onDelete('cascade');
        });

        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('hotel_group_id')->nullable()->after('hotel_guest_id');
            $table->foreign('hotel_group_id')->references('id')->on('hotel_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_reservations', function (Blueprint $table) {
            $table->dropForeign(['hotel_group_id']);
            $table->dropColumn('hotel_group_id');
        });
        Schema::dropIfExists('hotel_group_members');
        Schema::dropIfExists('hotel_groups');
    }
};
