<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('ready_at');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
            $table->unsignedBigInteger('voided_by')->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_reason', 'voided_by']);
        });
    }
};
