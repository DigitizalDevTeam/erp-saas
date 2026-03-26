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
            $table->boolean('identifier_tracking')->default(false)->after('affect_stock');
            $table->string('identifier_type', 10)->default('serial')->after('identifier_tracking'); // serial|mac|both
            $table->unsignedInteger('warranty_days')->nullable()->after('identifier_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_services', function (Blueprint $table) {
            $table->dropColumn(['identifier_tracking', 'identifier_type', 'warranty_days']);
        });
    }
};
