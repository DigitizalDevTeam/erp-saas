<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class CreditNoteRefundsAndApproval extends Migration
{
    /**
     * Credit Notes Phase 2 (2026-11-19) — Treasury Reembolso + the
     * approval option the client confirmed ("per option on setup we
     * can approve as per journal").
     *
     * - credit_notes gains status (0 pending / 1 approved / 2 rejected,
     *   mirroring JournalEntry) + approved_by / approved_at. The
     *   default is 1 (approved) so every note issued before this
     *   change — Phase 1 and legacy/void notes — stays approved.
     * - credit_note_refunds holds one refund per note (unique
     *   credit_note_id): the Treasury operation + journal it posted,
     *   the method and source account, the till session when the
     *   refund was recorded at the till, and both receipt files.
     * - 'approve credit note' and 'manage credit note refund'
     *   permissions, granted to every role that already holds
     *   'manage credit note'.
     */
    public function up()
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_notes', 'status')) {
                $table->tinyInteger('status')->default(1)->after('disposition');
            }
            if (! Schema::hasColumn('credit_notes', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('status');
            }
            if (! Schema::hasColumn('credit_notes', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });

        Schema::create('credit_note_refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('credit_note_id')->unique();
            $table->unsignedBigInteger('invoice_id')->default(0);
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->unsignedBigInteger('treasury_operation_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('method', 20);
            $table->unsignedBigInteger('financial_account_id');
            $table->unsignedBigInteger('petty_cash_fund_id')->nullable();
            $table->unsignedBigInteger('cash_session_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->string('transfer_receipt')->nullable();
            $table->string('signed_receipt')->nullable();
            $table->string('reference', 60)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['created_by'], 'cnr_created_by_idx');
            $table->index(['treasury_operation_id'], 'cnr_operation_idx');
        });

        if (Schema::hasTable('permissions') && Schema::hasTable('role_has_permissions')) {
            $permissions = [
                Permission::firstOrCreate(['name' => 'approve credit note', 'guard_name' => 'web']),
                Permission::firstOrCreate(['name' => 'manage credit note refund', 'guard_name' => 'web']),
            ];

            $roleIds = DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('permissions.name', 'manage credit note')
                ->distinct()
                ->pluck('role_has_permissions.role_id');

            foreach ($roleIds as $roleId) {
                foreach ($permissions as $permission) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permission->id,
                        'role_id' => $roleId,
                    ]);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down()
    {
        Schema::dropIfExists('credit_note_refunds');

        Schema::table('credit_notes', function (Blueprint $table) {
            if (Schema::hasColumn('credit_notes', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            if (Schema::hasColumn('credit_notes', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
            if (Schema::hasColumn('credit_notes', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
}
