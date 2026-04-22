<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'default_company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('default_company_id')->nullable()->after('created_by');
                $table->foreign('default_company_id')->references('id')->on('users')->nullOnDelete();
                $table->index('default_company_id');
            });
        }

        if (! Schema::hasTable('company_user')) {
            return;
        }

        DB::table('users')
            ->select('id', 'type', 'created_by', 'default_company_id')
            ->orderBy('id')
            ->chunk(500, function ($users): void {
                foreach ($users as $user) {
                    if ((string) $user->type === 'company' || (string) $user->type === 'super admin') {
                        continue;
                    }

                    $companyId = (int) ($user->created_by ?? 0);
                    if ($companyId <= 0) {
                        continue;
                    }

                    $companyExists = DB::table('users')
                        ->where('id', $companyId)
                        ->where('type', 'company')
                        ->exists();

                    if (! $companyExists) {
                        continue;
                    }

                    DB::table('company_user')->updateOrInsert(
                        [
                            'company_id' => $companyId,
                            'user_id' => (int) $user->id,
                        ],
                        [
                            'status' => 'active',
                            'is_default' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    if (empty($user->default_company_id)) {
                        DB::table('users')
                            ->where('id', (int) $user->id)
                            ->update(['default_company_id' => $companyId]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'default_company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['default_company_id']);
                $table->dropIndex(['default_company_id']);
                $table->dropColumn('default_company_id');
            });
        }
    }
};
