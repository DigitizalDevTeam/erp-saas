<?php

namespace App\Http\Controllers;

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

        ManagementRule::ensureSeeded($creatorId);
        ManagementCostCentre::ensureSeeded($creatorId);

        return view('management-rules.index', [
            'rules' => ManagementRule::where('created_by', $creatorId)->orderBy('sort_order')->get(),
            'groups' => ManagementRule::PL_GROUPS,
            'supplierDefaults' => ManagementSupplierCategory::where('created_by', $creatorId)
                ->with('rule')
                ->orderBy('supplier_name')
                ->get(),
            'centres' => ManagementCostCentre::where('created_by', $creatorId)->orderBy('code')->get(),
        ]);
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
