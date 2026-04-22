<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_user') || ! Schema::hasColumn('users', 'default_company_id')) {
            return;
        }

        DB::table('users')
            ->where('type', 'company')
            ->orderBy('id')
            ->chunk(200, function ($companies): void {
                foreach ($companies as $company) {
                    $companyId = (int) $company->id;

                    DB::table('company_user')->updateOrInsert(
                        ['company_id' => $companyId, 'user_id' => $companyId],
                        [
                            'role' => 'owner',
                            'status' => 'active',
                            'is_default' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    if (empty($company->default_company_id)) {
                        DB::table('users')
                            ->where('id', $companyId)
                            ->update(['default_company_id' => $companyId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Keep backfilled owner memberships on rollback.
    }
};
