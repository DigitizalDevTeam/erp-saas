<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\ManagementAccountMapping;
use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\ManagementSupplierCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Management Rules — the per-company Rules catalogue behind the
 * management P&L: categories -> P&L group + subgroup + order, the
 * supplier -> default category map, and the company's cost centres.
 *
 * The file's Rules tab has 57 categories; each company keeps its OWN
 * list and can change it. 101 / 102 are NOT fixed — a company defines
 * its own centres (or none at all). A rule in use by a document cannot
 * be deleted (the documents would lose their category).
 */
class ManagementRuleController extends Controller
{
    private function authorizeManagement(): void
    {
        $user = Auth::user();
        if ($user && ($user->can('menu.dashboard.accounting.reports.management.management_rules') || $user->can('manage report'))) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    public function index()
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        // Seeding is OPT-IN (the buttons below): a company with no
        // rules or centres stays empty until it loads the starters.
        return view('management-rules.index', [
            'rules' => ManagementRule::where('created_by', $creatorId)->orderBy('sort_order')->get(),
            'groups' => ManagementRule::PL_GROUPS,
            'supplierDefaults' => ManagementSupplierCategory::where('created_by', $creatorId)
                ->with('rule')
                ->orderBy('supplier_name')
                ->get(),
            'centres' => ManagementCostCentre::where('created_by', $creatorId)->orderBy('code')->get(),
            'mappings' => ManagementAccountMapping::where('created_by', $creatorId)
                ->with('account')
                ->orderBy('group')
                ->get(),
            'accounts' => ChartOfAccount::where('created_by', $creatorId)
                ->where('is_enabled', 1)
                ->orderBy('code')
                ->get(),
        ]);
    }

    /** Load the file's 57 starter categories (only the missing ones). */
    public function loadStarterCategories()
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $loaded = 0;
        foreach (ManagementRule::defaultCatalogue() as $row) {
            $rule = ManagementRule::firstOrCreate(
                ['created_by' => $creatorId, 'code' => $row['code']],
                $row
            );
            if ($rule->wasRecentlyCreated) {
                $loaded++;
            }
        }

        return redirect()->route('management-rules.index')
            ->with('success', __('Loaded :count starter categories.', ['count' => $loaded]));
    }

    /** Load the file's two starter cost centres 101 / 102. */
    public function loadStarterCentres()
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $loaded = 0;
        foreach (ManagementCostCentre::DEFAULT_CENTRES as $row) {
            $centre = ManagementCostCentre::firstOrCreate(
                ['created_by' => $creatorId, 'code' => $row['code']],
                $row
            );
            if ($centre->wasRecentlyCreated) {
                $loaded++;
            }
        }

        return redirect()->route('management-rules.index')
            ->with('success', __('Loaded :count starter cost centres.', ['count' => $loaded]));
    }

    /** Map a chart-of-accounts expense account to a P&L group. */
    public function storeAccountMapping(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $data = $request->validate([
            'chart_account_id' => [
                'required', 'integer',
                Rule::exists('chart_of_accounts', 'id')->where('created_by', $creatorId),
                Rule::unique('management_account_mappings', 'chart_account_id')->where('created_by', $creatorId),
            ],
            'group' => ['required', 'string', 'in:' . implode(',', ManagementRule::PL_GROUPS)],
            'subgroup' => ['nullable', 'string', 'max:255'],
        ]);

        ManagementAccountMapping::create(array_merge($data, ['created_by' => $creatorId]));

        return redirect()->route('management-rules.index')
            ->with('success', __('Account mapping saved successfully.'));
    }

    public function updateAccountMapping(Request $request, ManagementAccountMapping $mapping)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        if ((int) $mapping->created_by !== $creatorId) {
            abort(404);
        }

        $data = $request->validate([
            'group' => ['required', 'string', 'in:' . implode(',', ManagementRule::PL_GROUPS)],
            'subgroup' => ['nullable', 'string', 'max:255'],
        ]);
        $mapping->update($data);

        return redirect()->route('management-rules.index')
            ->with('success', __('Account mapping updated successfully.'));
    }

    public function destroyAccountMapping(ManagementAccountMapping $mapping)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        if ((int) $mapping->created_by !== $creatorId) {
            abort(404);
        }
        $mapping->delete();

        return redirect()->route('management-rules.index')
            ->with('success', __('Account mapping deleted successfully.'));
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $data = $this->validateRule($request, $creatorId);
        $data['created_by'] = $creatorId;
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) ManagementRule::where('created_by', $creatorId)->max('sort_order') + 1;
        }
        ManagementRule::create($data);

        return redirect()->route('management-rules.index')
            ->with('success', __('Category created successfully.'));
    }

    public function edit(ManagementRule $managementRule)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementRule, $creatorId);

        return view('management-rules.edit', [
            'rule' => $managementRule,
            'groups' => ManagementRule::PL_GROUPS,
        ]);
    }

    public function update(Request $request, ManagementRule $managementRule)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementRule, $creatorId);

        $managementRule->update($this->validateRule($request, $creatorId, $managementRule->id));

        return redirect()->route('management-rules.index')
            ->with('success', __('Category updated successfully.'));
    }

    public function destroy(ManagementRule $managementRule)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        $this->ensureOwned($managementRule, $creatorId);

        if (ManagementDocument::where('management_rule_id', $managementRule->id)->exists()) {
            return redirect()->route('management-rules.index')
                ->with('error', __('This category is used by documents and cannot be deleted.'));
        }
        ManagementSupplierCategory::where('management_rule_id', $managementRule->id)->delete();
        $managementRule->delete();

        return redirect()->route('management-rules.index')
            ->with('success', __('Category deleted successfully.'));
    }

    /** Save a supplier -> default category mapping. */
    public function storeSupplierDefault(Request $request)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();

        $data = $request->validate([
            'supplier_name' => ['required', 'string', 'max:255'],
            'management_rule_id' => [
                'required', 'integer',
                Rule::exists('management_rules', 'id')->where('created_by', $creatorId),
            ],
        ]);

        ManagementSupplierCategory::updateOrCreate(
            ['created_by' => $creatorId, 'supplier_name' => trim($data['supplier_name'])],
            ['management_rule_id' => $data['management_rule_id']]
        );

        return redirect()->route('management-rules.index')
            ->with('success', __('Supplier default saved successfully.'));
    }

    public function destroySupplierDefault(ManagementSupplierCategory $supplierDefault)
    {
        $this->authorizeManagement();
        $creatorId = \Auth::user()->creatorId();
        if ((int) $supplierDefault->created_by !== $creatorId) {
            abort(404);
        }
        $supplierDefault->delete();

        return redirect()->route('management-rules.index')
            ->with('success', __('Supplier default deleted successfully.'));
    }

    private function validateRule(Request $request, int $creatorId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('management_rules', 'code')->where('created_by', $creatorId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'group' => ['required', 'string', 'in:' . implode(',', ManagementRule::PL_GROUPS)],
            'subgroup' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function ensureOwned(ManagementRule $rule, int $creatorId): void
    {
        if ((int) $rule->created_by !== $creatorId) {
            abort(404);
        }
    }
}
