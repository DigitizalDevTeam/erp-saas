<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE journal_items SET debit = ROUND(debit, 2), credit = ROUND(credit, 2)');

        Schema::table('journal_items', function (Blueprint $table) {
            $table->decimal('debit', 15, 2)->default(0.00)->change();
            $table->decimal('credit', 15, 2)->default(0.00)->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_items', function (Blueprint $table) {
            $table->float('debit', 15, 2)->default(0.0)->change();
            $table->float('credit', 15, 2)->default(0.0)->change();
        });
    }
};
