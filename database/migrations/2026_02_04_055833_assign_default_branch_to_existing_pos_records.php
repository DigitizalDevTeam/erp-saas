<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Assign default branch to existing POS, warehouses, and pos_balances.
     */
    public function up(): void
    {
        // Get all unique creators (companies) that have POS or warehouse data
        $creators = collect();

        if (Schema::hasTable('pos')) {
            $creators = $creators->merge(
                DB::table('pos')->whereNotNull('created_by')->pluck('created_by')
            );
        }
        if (Schema::hasTable('warehouses')) {
            $creators = $creators->merge(
                DB::table('warehouses')->whereNotNull('created_by')->pluck('created_by')
            );
        }
        if (Schema::hasTable('pos_balances') && Schema::hasColumn('pos_balances', 'user_id')) {
            $creators = $creators->merge(
                DB::table('pos_balances')->whereNotNull('user_id')->pluck('user_id')
            );
        }

        $creators = $creators->unique()->filter();

        foreach ($creators as $creatorId) {
            // Get or create default branch for this company
            $branch = DB::table('branches')
                ->where('created_by', $creatorId)
                ->first();

            if (!$branch) {
                // Create main branch for companies without one
                $branchId = DB::table('branches')->insertGetId([
                    'name' => 'Main Branch',
                    'address' => null,
                    'timezone' => 'UTC',
                    'is_active' => true,
                    'created_by' => $creatorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $branchId = $branch->id;
            }

            // Update pos records
            if (Schema::hasTable('pos') && Schema::hasColumn('pos', 'branch_id')) {
                DB::table('pos')
                    ->where('created_by', $creatorId)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);
            }

            // Update warehouses
            if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'branch_id')) {
                DB::table('warehouses')
                    ->where('created_by', $creatorId)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);
            }

            // Update pos_payments via pos relation
            if (Schema::hasTable('pos_payments') && Schema::hasColumn('pos_payments', 'branch_id')) {
                $posIds = DB::table('pos')->where('branch_id', $branchId)->pluck('id');
                if ($posIds->isNotEmpty()) {
                    DB::table('pos_payments')
                        ->whereIn('pos_id', $posIds)
                        ->whereNull('branch_id')
                        ->update(['branch_id' => $branchId]);
                }
            }

            // Update pos_balances (uses user_id as creator)
            if (Schema::hasTable('pos_balances') && Schema::hasColumn('pos_balances', 'branch_id')) {
                DB::table('pos_balances')
                    ->where('user_id', $creatorId)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => $branchId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reliably reverse - branch assignments would be lost
        // Only schema reversions are in the previous migration
    }
};
