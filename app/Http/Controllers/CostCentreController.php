<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Models\Branch;
use App\Models\CostCentre;
use App\Models\User;
use App\Services\CostCentreService;
use App\Services\CostCentreSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Cost Centres — Phase 0: master data only (list/tree, form, settings).
 * No allocations, budgets, approvals or reports in this phase.
 */
class CostCentreController extends Controller
{
    public function __construct(
        protected CostCentreService $service,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filtering = $request->filled('search') || $request->filled('status') || $request->filled('branch_id');

        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        if ($filtering) {
            $centres = CostCentre::forCompany($creatorId)
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = '%'.$request->input('search').'%';
                    $q->where(function ($inner) use ($search) {
                        $inner->where('name', 'like', $search)->orWhere('code', 'like', $search);
                    });
                })
                ->with(['parent', 'branch', 'manager'])
                ->orderBy('code')
                ->paginate(20);

            $rows = array_map(fn ($centre) => ['centre' => $centre, 'depth' => 0], $centres->items());

            return view('cost-centres.index', compact('rows', 'centres', 'branches', 'filtering'));
        }

        $rows = $this->service->treeRows($creatorId);
        // Eager-load the relations the tree rows display.
        $ids = array_map(fn ($row) => $row['centre']->id, $rows);
        $loaded = CostCentre::whereIn('id', $ids)->with(['parent', 'branch', 'manager'])->get()->keyBy('id');
        foreach ($rows as &$row) {
            $row['centre'] = $loaded->get($row['centre']->id, $row['centre']);
        }
        unset($row);

        $centres = null;

        return view('cost-centres.index', compact('rows', 'centres', 'branches', 'filtering'));
    }

    public function create()
    {
        if (! Auth::user()->can('create cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('cost-centres.form', array_merge($this->formData($creatorId), [
            'centre' => new CostCentre(['status' => CostCentre::STATUS_ACTIVE]),
        ]));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request, $creatorId);

        try {
            $centre = $this->service->create($data, $creatorId, (int) Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cost-centres.index')
            ->with('success', __('Cost centre :code successfully created.', ['code' => $centre->code]));
    }

    public function edit(CostCentre $costCentre)
    {
        if (! Auth::user()->can('edit cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->service->assertSameCompany($costCentre, (int) Auth::user()->creatorId());

        $creatorId = (int) Auth::user()->creatorId();

        return view('cost-centres.form', array_merge($this->formData($creatorId, $costCentre), [
            'centre' => $costCentre,
        ]));
    }

    public function update(Request $request, CostCentre $costCentre)
    {
        if (! Auth::user()->can('edit cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->service->assertSameCompany($costCentre, $creatorId);

        $data = $this->validateForm($request, $creatorId, $costCentre->id);

        try {
            $centre = $this->service->update($costCentre, $data, $creatorId, (int) Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cost-centres.index')
            ->with('success', __('Cost centre :code successfully updated.', ['code' => $centre->code]));
    }

    public function deactivate(CostCentre $costCentre)
    {
        if (! Auth::user()->can('deactivate cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->service->assertSameCompany($costCentre, $creatorId);

        $centre = $this->service->deactivate($costCentre, $creatorId, (int) Auth::id());

        return redirect()->route('cost-centres.index')
            ->with('success', __('Cost centre :code deactivated. It stays reportable for history but cannot take new transactions.', ['code' => $centre->code]));
    }

    public function destroy(CostCentre $costCentre)
    {
        if (! Auth::user()->can('deactivate cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $this->service->assertSameCompany($costCentre, $creatorId);

        try {
            $this->service->delete($costCentre, $creatorId);
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('cost-centres.index')
            ->with('success', __('Cost centre successfully deleted.'));
    }

    public function settings()
    {
        if (! Auth::user()->can('edit cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('cost-centres.settings', [
            'settings' => CostCentreSettings::forCompany($creatorId),
            // Phase 5 (D3): candidates for the finance controller role
            // — the company user and its staff.
            'users' => User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function saveSettings(Request $request)
    {
        if (! Auth::user()->can('edit cost centres')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $request->validate([
            'budget_policy' => ['nullable', 'string', Rule::in(CostCentreSettings::BUDGET_POLICIES)],
            'budget_approval_threshold' => ['nullable', 'numeric', 'min:0'],
            'budget_finance_controller_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })],
            'dimension_on_cash_transactions' => ['nullable', 'string', Rule::in(CostCentreSettings::DIMENSION_ON_CASH_VALUES)],
            'period_lock_policy' => ['nullable', 'string', Rule::in(CostCentreSettings::PERIOD_LOCK_POLICIES)],
            'default_allocation_basis' => ['nullable', 'string', Rule::in(CostCentreSettings::ALLOCATION_BASES)],
            'rounding_account' => ['nullable', 'string', 'max:50'],
        ]);

        CostCentreSettings::save($creatorId, $request->all());

        return redirect()->route('cost-centres.settings')
            ->with('success', __('Cost centre settings saved.'));
    }

    protected function formData(int $creatorId, ?CostCentre $excluding = null): array
    {
        // Parent candidates: every centre of this company except the one
        // being edited and its descendants (those would create a cycle).
        $excludedIds = $excluding ? array_merge([$excluding->id], $this->service->descendantIds($excluding)) : [];

        return [
            'parents' => CostCentre::forCompany($creatorId)
                ->whereNotIn('id', $excludedIds)
                ->orderBy('code')->get(['id', 'code', 'name', 'parent_id']),
            'branches' => Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'managers' => User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }

    protected function validateForm(Request $request, int $creatorId, ?int $ignoreId = null): array
    {
        return $request->validate([
            // Unique per company, including soft-deleted rows: codes are
            // never reused (the service re-checks this as a domain rule).
            'code' => ['required', 'string', 'max:50', Rule::unique('cost_centres', 'code')->where('created_by', $creatorId)->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', Rule::exists('cost_centres', 'id')->where('created_by', $creatorId)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('created_by', $creatorId)],
            'manager_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'status' => ['required', 'string', Rule::in(CostCentre::STATUSES)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
    }
}
