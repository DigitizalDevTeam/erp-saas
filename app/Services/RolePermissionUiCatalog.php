<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Spatie\Permission\Models\Permission;

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
            'manage printers' => 'Manage Printers',
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
            'manage printers' => 'pos',
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

    /**
     * Permission names the role EDIT form actually controls for this editor.
     *
     * This mirrors resources/views/role/edit.blade.php exactly: a checkbox
     * is rendered only when (a) its module tab is shown for the editor's
     * plan and (b) its name is in the editor's permission map. Anything
     * NOT in this set is never shown on the form, so RoleController::update
     * must preserve it as-is instead of treating its absence from the POST
     * as a revoke — otherwise every save silently deletes the role's
     * permissions for plan-hidden modules (CRM/Project for Top ++
     * companies; HRM/Account/POS/CRM/Project for the super admin, whose
     * plan is null and hides every gated tab).
     *
     * NOTE: User::show_dashboard() reads Auth::user(), so $user must be
     * the authenticated editor — exactly as the blade uses Auth::user().
     *
     * @return array<int, string>
     */
    public static function formControlledNames(User $user): array
    {
        // The editor's permission map, exactly as RoleController::edit()
        // builds it: super admin / company see every permission, staff
        // editors see only the permissions their own roles hold.
        if ($user->type === 'super admin' || $user->type === 'company') {
            $permissionMap = Permission::query()->pluck('name', 'id')->all();
        } else {
            $permissionMap = [];
            foreach ($user->roles as $editorRole) {
                $permissionMap += $editorRole->permissions->pluck('name', 'id')->all();
            }
        }

        $plan = Plan::getPlan($user->show_dashboard());
        $planAllows = fn (string $flag): bool => ! empty($plan) && (int) $plan->{$flag} === 1;

        // Staff + Sidebar tabs always render; the module tabs only when
        // the editor's plan enables them (same @if conditions as the blade).
        $modules = self::staffModules($user->type);
        if ($planAllows('crm')) {
            $modules = array_merge($modules, self::crmModules());
        }
        if ($planAllows('project')) {
            $modules = array_merge($modules, self::projectModules());
        }
        if ($planAllows('hrm')) {
            $modules = array_merge($modules, self::hrmModules());
        }
        if ($planAllows('account')) {
            $modules = array_merge($modules, self::accountModules());
        }
        $posTab = $planAllows('pos') || (! empty($plan) && (int) ($plan->stock ?? 0) === 1);
        if ($posTab) {
            // Stock-only plans render just the warehouse row, no POS modules.
            $modules = array_merge($modules, $planAllows('pos') ? self::posModules() : ['warehouse']);
        }

        $names = [];
        foreach ($modules as $module) {
            foreach (self::prefixes() as [$prefix]) {
                $names[] = $prefix . $module;
            }
        }

        // Standalone permissions render only on their tab (module_permissions
        // partial, $specialsTab): 'account' specials on the Account tab,
        // 'pos' specials on the POS tab — and only when pos == 1, not
        // for a stock-only plan.
        foreach (self::specialPermissionTabs() as $specialName => $tab) {
            if (($tab === 'account' && $planAllows('account'))
                || ($tab === 'pos' && $planAllows('pos'))) {
                $names[] = $specialName;
            }
        }

        // Hotel checkboxes render only when the editor's map holds at least
        // one hotel permission (hotel_permissions partial).
        if (HotelPermissionCatalog::hasAnyInPermissionMap($permissionMap)) {
            $names = array_merge($names, HotelPermissionCatalog::allNames());
        }

        // The Sidebar tab always renders.
        $names = array_merge($names, SidebarMenuPermissionCatalog::allPermissionNames());

        // A checkbox exists only for names the editor's map actually holds.
        return array_values(array_unique(array_intersect($names, array_values($permissionMap))));
    }
}
