<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_transfers', 'transfer_batch_id')) {
                $table->uuid('transfer_batch_id')->nullable()->after('id');
                $table->index('transfer_batch_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_transfers', 'transfer_batch_id')) {
                $table->dropIndex(['transfer_batch_id']);
                $table->dropColumn('transfer_batch_id');
            }
        });
    }
};
