<?php

namespace App\Services;

/**
 * Modules, prefixes, and special permissions rendered on Role create/edit forms.
 * Used to keep UI assignable names in sync with RoleController safe sync.
 */
class RolePermissionUiCatalog
{
    /**
     * Prefixes tried as "{prefix} {module}" for each module row.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function prefixes(): array
    {
        return [
            ['view ', 'View'],
            ['add ', 'Add'],
            ['move ', 'Move'],
            ['manage ', 'Manage'],
            ['create ', 'Create'],
            ['edit ', 'Edit'],
            ['delete ', 'Delete'],
            ['show ', 'Show'],
            ['send ', 'Send'],
            ['convert ', 'Convert'],
            ['copy ', 'Copy'],
            ['duplicate ', 'Duplicate'],
            ['create payment ', 'Create Payment'],
            ['delete payment ', 'Delete Payment'],
            ['income ', 'Income'],
            ['expense ', 'Expense'],
            ['income vs expense ', 'Income VS Expense'],
            ['loss & profit ', 'Loss & Profit'],
            ['tax ', 'Tax'],
            ['invoice ', 'Invoice'],
            ['bill ', 'Bill'],
            ['stock ', 'Stock'],
            ['statement ', 'Statement'],
            ['balance sheet ', 'Balance Sheet'],
            ['ledger ', 'Ledger'],
            ['trial balance ', 'Trial Balance'],
            ['authorize ', 'Authorize'],
            ['approve ', 'Approve'],
            ['reject ', 'Reject'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function staffModules(?string $userType = null): array
    {
        $modules = [
            'user',
            'role',
            'client',
            'product & service',
            'constant unit',
            'constant tax',
            'constant category',
            'zoom meeting',
            'company settings',
            'print settings',
            'company plan',
            'order',
        ];

        if ($userType === 'company') {
            $modules[] = 'language';
            $modules[] = 'permission';
        }

        return $modules;
    }

    /**
     * @return array<int, string>
     */
    public static function crmModules(): array
    {
        return [
            'crm dashboard',
            'sales dashboard',
            'lead',
            'pipeline',
            'lead stage',
            'source',
            'label',
            'lead email',
            'lead call',
            'deal',
            'stage',
            'task',
            'form builder',
            'form response',
            'form field',
            'contract',
            'contract type',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function projectModules(): array
    {
        return [
            'project dashboard',
            'project',
            'milestone',
            'grant chart',
            'project stage',
            'timesheet',
            'project expense',
            'project task',
            'activity',
            'CRM activity',
            'project task stage',
            'bug report',
            'bug status',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function hrmModules(): array
    {
        return [
            'hrm dashboard',
            'employee',
            'employee profile',
            'department',
            'designation',
            'branch',
            'document type',
            'document',
            'payslip type',
            'allowance',
            'commission',
            'allowance option',
            'loan option',
            'deduction option',
            'loan',
            'saturation deduction',
            'other payment',
            'overtime',
            'set salary',
            'pay slip',
            'company policy',
            'appraisal',
            'goal tracking',
            'goal type',
            'indicator',
            'competencies',
            'event',
            'meeting',
            'training',
            'trainer',
            'training type',
            'award',
            'award type',
            'resignation',
            'travel',
            'promotion',
            'complaint',
            'warning',
            'termination',
            'termination type',
            'job application',
            'job application note',
            'job onBoard',
            'job category',
            'job',
            'job stage',
            'custom question',
            'interview schedule',
            'career',
            'estimation',
            'holiday',
            'transfer',
            'announcement',
            'leave',
            'leave type',
            'attendance',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function accountModules(): array
    {
        return [
            'account dashboard',
            'proposal',
            'invoice',
            'bill',
            'revenue',
            'payment',
            'proposal product',
            'invoice product',
            'bill product',
            'goal',
            'credit note',
            'debit note',
            'bank account',
            'bank transfer',
            'transaction',
            'customer',
            'vender',
            'constant custom field',
            'assets',
            'chart of account',
            'journal entry',
            'report',
            'expense',
            'expense category',
            'budget plan',
            'print settings',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function posModules(): array
    {
        return [
            'pos dashboard',
            'warehouse',
            'quotation',
            'purchase',
            'pos',
            'barcode',
            'pos production',
        ];
    }

    /**
     * Standalone permission rows (exact name => label).
     *
     * @return array<string, string>
     */
    public static function specialPermissions(): array
    {
        return [
            'give pos credit' => 'Allow credit payment at POS',
            'change pos print settings' => 'Change POS print settings',
            'pos restaurant full' => 'POS Restaurant Full',
            'pos restaurant waiter' => 'POS Restaurant Waiter',
            'pos restaurant manager' => 'POS Restaurant Manager',
            'reject invoice authorization' => 'Reject Invoice Authorization',
        ];
    }

    /**
     * Which tab each special permission belongs to (for UI grouping).
     *
     * @return array<string, string> permission name => tab key
     */
    public static function specialPermissionTabs(): array
    {
        return [
            'give pos credit' => 'pos',
            'change pos print settings' => 'pos',
            'pos restaurant full' => 'pos',
            'pos restaurant waiter' => 'pos',
            'pos restaurant manager' => 'pos',
            'reject invoice authorization' => 'account',
        ];
    }

    /**
     * Every permission name the role UI can assign (for safe sync preserve logic).
     *
     * @return array<int, string>
     */
    public static function assignableNames(?string $userType = null): array
    {
        $modules = array_merge(
            self::staffModules($userType),
            self::crmModules(),
            self::projectModules(),
            self::hrmModules(),
            self::accountModules(),
            self::posModules()
        );

        $names = [];
        foreach ($modules as $module) {
            foreach (self::prefixes() as [$prefix]) {
                $names[] = $prefix . $module;
            }
        }

        foreach (array_keys(self::specialPermissions()) as $name) {
            $names[] = $name;
        }

        foreach (HotelPermissionCatalog::allNames() as $name) {
            $names[] = $name;
        }

        foreach (SidebarMenuPermissionCatalog::allPermissionNames() as $name) {
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
