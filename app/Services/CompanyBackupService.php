<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Company-scoped database backup.
 *
 * Builds a complete SQL dump (schema for every table, data only for the
 * companies the logged-in admin belongs to) and streams it out in
 * chunks, so a large database never has to sit in memory at once.
 *
 * Scoping rules, in order:
 *  - EXCLUDED_TABLES: schema only, never any rows (ephemeral or
 *    super-admin data: queued jobs, password resets, auth tokens,
 *    the migrations history, public landing submissions, the
 *    backups log itself).
 *  - GLOBAL_TABLES: every row (shared reference data with no
 *    company content: languages, plans, the permission catalog).
 *  - Special cases: users, roles and the Spatie pivots, company
 *    memberships, settings, chat messages, bill accounts and the
 *    user/branch/warehouse pivots — each scoped by its own key.
 *  - PARENT_TABLES: child rows (invoice lines, journal items, ...)
 *    scoped through their parent table's company.
 *  - Any other table WITH a created_by column: created_by IN the
 *    admin's companies (the codebase-wide company key — staff rows
 *    carry their company's id via creatorId()).
 *  - Anything left without a company key: schema only, and the
 *    table name is listed in the dump header/footer so the gap is
 *    visible instead of silent.
 *
 * The dump is MySQL-flavoured (the ERP runs on MySQL); on SQLite
 * (the test suite) the CREATE statements are read from
 * sqlite_master so the same scoping logic can be tested end to end.
 */
class CompanyBackupService
{
    /** @var int[] */
    protected array $companyIds;

    /** @var int[] */
    protected array $scopedUserIds;

    /** @var int[] */
    protected array $includedRoleIds;

    /** @var string[] */
    protected array $skippedTables = [];

    /**
     * Tables whose rows are never dumped (schema only).
     */
    public const EXCLUDED_TABLES = [
        'backups',
        'failed_jobs',
        'join_us',
        'landing_page_settings',
        'migrations',
        'password_resets',
        'personal_access_tokens',
    ];

    /**
     * Tables whose rows are all shared reference data — dump whole.
     */
    public const GLOBAL_TABLES = [
        'coupons',
        'genrate_payslip_options',
        'languages',
        'notification_templates',
        'permissions',
        'plans',
        'set_salaries',
        'templates',
    ];

    /**
     * Child table => [parent table, foreign key, parent scope column].
     * The child rows are kept when their parent belongs to one of the
     * admin's companies.
     */
    public const PARENT_TABLES = [
        'bank_reconciliation_matches' => ['bank_reconciliation_sessions', 'session_id', 'created_by'],
        'bank_statement_lines' => ['bank_reconciliation_sessions', 'session_id', 'created_by'],
        'bill_payments' => ['bills', 'bill_id', 'created_by'],
        'bill_products' => ['bills', 'bill_id', 'created_by'],
        'client_deals' => ['deals', 'deal_id', 'created_by'],
        'contract_attachment' => ['contracts', 'contract_id', 'created_by'],
        'contract_comment' => ['contracts', 'contract_id', 'created_by'],
        'contract_notes' => ['contracts', 'contract_id', 'created_by'],
        'cost_centre_allocation_lines' => ['cost_centre_allocations', 'allocation_id', 'created_by'],
        'custom_field_values' => ['custom_fields', 'field_id', 'created_by'],
        'customer_billing_addresses' => ['customers', 'customer_id', 'created_by'],
        'customer_shipping_addresses' => ['customers', 'customer_id', 'created_by'],
        'deal_calls' => ['deals', 'deal_id', 'created_by'],
        'deal_emails' => ['deals', 'deal_id', 'created_by'],
        'deal_files' => ['deals', 'deal_id', 'created_by'],
        'deal_tasks' => ['deals', 'deal_id', 'created_by'],
        'email_template_langs' => ['email_templates', 'parent_id', 'created_by'],
        'form_field_responses' => ['form_builders', 'form_id', 'created_by'],
        'form_responses' => ['form_builders', 'form_id', 'created_by'],
        'goods_receipt_products' => ['goods_receipts', 'goods_receipt_id', 'created_by'],
        'hotel_group_members' => ['hotel_groups', 'hotel_group_id', 'created_by'],
        'invoice_payments' => ['invoices', 'invoice_id', 'created_by'],
        'invoice_products' => ['invoices', 'invoice_id', 'created_by'],
        'journal_items' => ['journal_entries', 'journal', 'created_by'],
        'lead_activity_logs' => ['leads', 'lead_id', 'created_by'],
        'lead_calls' => ['leads', 'lead_id', 'created_by'],
        'lead_emails' => ['leads', 'lead_id', 'created_by'],
        'lead_files' => ['leads', 'lead_id', 'created_by'],
        'low_stock_notification_reads' => ['low_stock_notifications', 'notification_id', 'company_id'],
        'milestones' => ['projects', 'project_id', 'created_by'],
        'pos_products' => ['pos', 'pos_id', 'created_by'],
        'proforma_products' => ['proformas', 'proforma_id', 'created_by'],
        'project_email_templates' => ['projects', 'project_id', 'created_by'],
        'proposal_products' => ['proposals', 'proposal_id', 'created_by'],
        'purchase_payment_proposal_items' => ['purchase_payment_proposals', 'purchase_payment_proposal_id', 'created_by'],
        'purchase_payments' => ['purchases', 'purchase_id', 'created_by'],
        'purchase_products' => ['purchases', 'purchase_id', 'created_by'],
        'purchase_proforma_products' => ['purchase_proformas', 'purchase_proforma_id', 'created_by'],
        'purchase_quotation_products' => ['purchase_quotations', 'purchase_quotation_id', 'created_by'],
        'purchase_requisition_products' => ['purchase_requisitions', 'purchase_requisition_id', 'created_by'],
        'purchase_return_products' => ['purchase_returns', 'purchase_return_id', 'created_by'],
        'purchase_rfq_venders' => ['purchase_rfqs', 'purchase_rfq_id', 'created_by'],
        'quotation_products' => ['quotations', 'quotation_id', 'created_by'],
        'stock_audit_lines' => ['stock_audits', 'stock_audit_id', 'created_by'],
        'stock_introduction_lines' => ['stock_introductions', 'stock_introduction_id', 'created_by'],
        'treasury_approval_decisions' => ['treasury_payment_requests', 'payment_request_id', 'created_by'],
        'treasury_approval_steps' => ['treasury_payment_requests', 'payment_request_id', 'created_by'],
    ];

    /**
     * Tables scoped by a single user column => the column name.
     */
    public const USER_SCOPED_TABLES = [
        'activity_logs' => 'user_id',
        'ch_favorites' => 'user_id',
        'notifications' => 'user_id',
        'orders' => 'user_id',
        'plan_requests' => 'user_id',
        'transaction_orders' => 'req_user_id',
        'user_contacts' => 'user_id',
        'user_coupons' => 'user',
        'user_to_dos' => 'user_id',
        'users_verify' => 'user_id',
    ];

    /**
     * Tables scoped by a company_id column (no created_by).
     */
    public const COMPANY_SCOPED_TABLES = [
        'dimension_audit_events',
        'low_stock_notifications',
        'referral_transactions',
    ];

    /**
     * Pivot tables: kept when the user is in scope OR the linked
     * parent (branch / warehouse / project / deal / lead / template)
     * belongs to one of the admin's companies.
     * Table => [parent table, foreign key].
     */
    public const PIVOT_TABLES = [
        'branch_user' => ['branches', 'branch_id'],
        'pos_balances' => ['branches', 'branch_id'],
        'project_users' => ['projects', 'project_id'],
        'user_deals' => ['deals', 'deal_id'],
        'user_email_templates' => ['email_templates', 'template_id'],
        'user_leads' => ['leads', 'lead_id'],
        'warehouse_user' => ['warehouses', 'warehouse_id'],
    ];

    public function __construct(protected User $admin)
    {
        $this->companyIds = $this->resolveCompanyIds();
        $this->scopedUserIds = $this->resolveScopedUserIds();
        $this->includedRoleIds = $this->resolveIncludedRoleIds();
    }

    /**
     * Owner user ids of the companies the admin belongs to.
     */
    public function companyIds(): array
    {
        return $this->companyIds;
    }

    /**
     * The company User models, for display on the download page.
     */
    public function companies()
    {
        return $this->admin->companiesForSelection();
    }

    /**
     * User ids whose rows belong to the backup: the company owners,
     * staff created by those companies, staff whose default company
     * is one of them, and the admin themself.
     */
    public function scopedUserIds(): array
    {
        return $this->scopedUserIds;
    }

    /**
     * Role ids whose rows belong to the backup: roles created by the
     * admin's companies, plus any role held by an in-scope user.
     */
    public function includedRoleIds(): array
    {
        return $this->includedRoleIds;
    }

    /**
     * Tables whose data rows were left out (schema only), so the dump
     * itself says what it does not contain.
     */
    public function skippedTables(): array
    {
        return $this->skippedTables;
    }

    public function filename(): string
    {
        return 'company-backup-' . now()->format('Y-m-d_His') . '.sql';
    }

    /**
     * All tables in the database, in name order.
     *
     * @return string[]
     */
    public function tables(): array
    {
        $names = [];
        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? ($table['name'] ?? null) : ($table->name ?? null);
            if ($name === null || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);

        return $names;
    }

    /**
     * Query for the rows of a table that belong in the backup, or
     * null when the table contributes schema only.
     */
    public function rowQuery(string $table): ?Builder
    {
        if (in_array($table, self::EXCLUDED_TABLES, true)) {
            return null;
        }

        if (in_array($table, self::GLOBAL_TABLES, true)) {
            return DB::table($table);
        }

        return match ($table) {
            'users' => $this->usersQuery(),
            'roles' => $this->rolesQuery(),
            'role_has_permissions' => DB::table($table)->whereIn('role_id', $this->includedRoleIds),
            'model_has_roles' => DB::table($table)->where('model_type', User::class)->whereIn('model_id', $this->scopedUserIds),
            'model_has_permissions' => DB::table($table)->where('model_type', User::class)->whereIn('model_id', $this->scopedUserIds),
            'company_user' => DB::table($table)->whereIn('company_id', $this->companyIds),
            'settings' => DB::table($table)->whereIn('created_by', $this->companyIds),
            'ch_messages' => DB::table($table)
                ->whereIn('from_id', $this->scopedUserIds)
                ->orWhereIn('to_id', $this->scopedUserIds),
            'bill_accounts' => $this->billAccountsQuery(),
            default => $this->genericRowQuery($table),
        };
    }

    /**
     * Stream the whole dump as string chunks: header, one section per
     * table (DROP + CREATE + INSERTs), footer summary.
     */
    public function generate(): iterable
    {
        $companyNames = $this->companies()->pluck('name')->all();
        $generatedAt = now()->toDateTimeString();

        yield "-- Company backup generated {$generatedAt} by {$this->admin->name} ({$this->admin->email})\n";
        yield '-- Companies included: ' . (empty($companyNames) ? '(none)' : implode(', ', $companyNames)) . "\n";
        yield "-- Only data belonging to the companies above is included. Tables listed under\n";
        yield "-- \"schema only\" below carry their structure but no rows.\n";
        yield "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n\n";

        $counts = [];

        foreach ($this->tables() as $table) {
            $create = $this->createTableSql($table);
            yield "--\n-- Table structure for table `{$table}`\n--\n\n";
            yield "DROP TABLE IF EXISTS `{$table}`;\n";
            if ($create !== null) {
                yield $create . ";\n\n";
            }

            try {
                $query = $this->rowQuery($table);
                if ($query === null) {
                    $this->skippedTables[] = $table;
                    $counts[$table] = 0;
                    continue;
                }

                $query->orderBy($this->orderColumn($table));
                $count = 0;
                $columns = $this->columnNames($table);
                $quotedColumns = '`' . implode('`,`', $columns) . '`';

                $page = 1;
                do {
                    $rows = (clone $query)->forPage($page, 500)->get();
                    $lines = '';
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ((array) $row as $value) {
                            $values[] = $this->escapeValue($value);
                        }
                        $lines .= "INSERT INTO `{$table}` ({$quotedColumns}) VALUES (" . implode(',', $values) . ");\n";
                        $count++;
                    }
                    if ($lines !== '') {
                        yield $lines;
                    }
                    $page++;
                } while ($rows->count() === 500);

                $counts[$table] = $count;
                yield "\n";
            } catch (\Throwable $e) {
                // One unreadable table must never kill the whole
                // backup mid-stream (a truncated download looks
                // complete in the browser). This table goes
                // schema-only and is named in the footer.
                $this->skippedTables[] = $table;
                $counts[$table] = 0;
                yield "-- Skipped rows for `{$table}`: could not read its rows (" . str_replace(["\r", "\n"], ' ', $e->getMessage()) . ")\n\n";
            }
        }

        yield "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n";
        yield "--\n-- Backup summary (rows included per table)\n--\n";
        foreach ($counts as $table => $count) {
            yield "-- {$table}: {$count}\n";
        }
        yield '-- Schema only (no rows dumped): ' . (empty($this->skippedTables) ? '(none)' : implode(', ', $this->skippedTables)) . "\n";
    }

    protected function resolveCompanyIds(): array
    {
        $ids = $this->admin->companiesForSelection()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($this->admin->type === 'company' && ! in_array((int) $this->admin->id, $ids, true)) {
            $ids[] = (int) $this->admin->id;
        }

        return array_values(array_unique($ids));
    }

    protected function resolveScopedUserIds(): array
    {
        $ids = DB::table('users')
            ->whereIn('id', $this->companyIds)
            ->orWhereIn('created_by', $this->companyIds)
            ->orWhereIn('default_company_id', $this->companyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ids[] = (int) $this->admin->id;

        return array_values(array_unique($ids));
    }

    protected function resolveIncludedRoleIds(): array
    {
        $ids = Role::whereIn('created_by', $this->companyIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $held = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('model_id', $this->scopedUserIds)
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($ids, $held)));
    }

    protected function usersQuery(): Builder
    {
        return DB::table('users')->where(function (Builder $query) {
            $query->whereIn('id', $this->companyIds)
                ->orWhereIn('created_by', $this->companyIds)
                ->orWhereIn('default_company_id', $this->companyIds)
                ->orWhere('id', (int) $this->admin->id);
        });
    }

    protected function rolesQuery(): Builder
    {
        return DB::table('roles')
            ->whereIn('created_by', $this->companyIds)
            ->orWhereIn('id', $this->includedRoleIds);
    }

    /**
     * bill_accounts is polymorphic: type 'Bill' points at bills,
     * type 'Payment' at bill_payments.
     */
    protected function billAccountsQuery(): Builder
    {
        $companyIds = $this->companyIds;

        return DB::table('bill_accounts')->where(function (Builder $query) use ($companyIds) {
            $query->where(function (Builder $sub) use ($companyIds) {
                $sub->where('type', 'Bill')
                    ->whereIn('ref_id', fn (Builder $q) => $q->select('id')->from('bills')->whereIn('created_by', $companyIds));
            })->orWhere(function (Builder $sub) use ($companyIds) {
                $sub->where('type', 'Payment')
                    ->whereIn('ref_id', function (Builder $q) use ($companyIds) {
                        $q->select('id')->from('bill_payments')
                            ->whereIn('bill_id', fn (Builder $inner) => $inner->select('id')->from('bills')->whereIn('created_by', $companyIds));
                    });
            });
        });
    }

    protected function genericRowQuery(string $table): ?Builder
    {
        if (isset(self::USER_SCOPED_TABLES[$table])) {
            return DB::table($table)->whereIn(self::USER_SCOPED_TABLES[$table], $this->scopedUserIds);
        }

        if (in_array($table, self::COMPANY_SCOPED_TABLES, true)) {
            return DB::table($table)->whereIn('company_id', $this->companyIds);
        }

        if (isset(self::PIVOT_TABLES[$table])) {
            [$parent, $fk] = self::PIVOT_TABLES[$table];

            return DB::table($table)
                ->whereIn('user_id', $this->scopedUserIds)
                ->orWhereIn($fk, fn (Builder $q) => $q->select('id')->from($parent)->whereIn('created_by', $this->companyIds));
        }

        if (isset(self::PARENT_TABLES[$table])) {
            [$parent, $fk, $scopeColumn] = self::PARENT_TABLES[$table];

            return DB::table($table)
                ->whereIn($fk, fn (Builder $q) => $q->select('id')->from($parent)->whereIn($scopeColumn, $this->companyIds));
        }

        if (Schema::hasColumn($table, 'created_by')) {
            return DB::table($table)->whereIn('created_by', $this->companyIds);
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function columnNames(string $table): array
    {
        $names = [];
        foreach (Schema::getColumns($table) as $column) {
            $names[] = is_array($column) ? $column['name'] : $column->name;
        }

        return $names;
    }

    protected function orderColumn(string $table): string
    {
        return Schema::hasColumn($table, 'id') ? 'id' : $this->columnNames($table)[0];
    }

    protected function createTableSql(string $table): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);

            return $row->sql ?? null;
        }

        $row = DB::selectOne("SHOW CREATE TABLE `{$table}`");

        return $row->{'Create Table'} ?? null;
    }

    protected function escapeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $quoted = DB::connection()->getPdo()->quote((string) $value);

        return $quoted === false ? "''" : $quoted;
    }
}
