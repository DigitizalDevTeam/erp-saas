<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Fixed Asset Categories — Phase 0: master data only (list,
 * form, deactivate). Depreciation defaults per category live
 * here for later phases (Phase 3 reads them); no depreciation
 * is calculated in this phase.
 */
class FixedAssetCategoryController extends Controller
{
    public function __construct(
        protected FixedAssetService $service,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed asset categories')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $categories = FixedAssetCategory::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)->orWhere('code', 'like', $search);
                });
            })
            ->when($request->filled('classification'), fn ($q) => $q->where('classification', $request->input('classification')))
            ->orderBy('code')
            ->paginate(20);

        return view('fixed-assets.categories.index', compact('categories'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage fixed asset categories')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('fixed-assets.categories.form', array_merge($this->formData($creatorId), [
            'category' => new FixedAssetCategory([
                'classification' => FixedAssetCategory::CLASSIFICATION_TANGIBLE,
                'default_life_unit' => FixedAssetCategory::LIFE_UNIT_YEAR,
                'depreciation_method' => FixedAssetCategory::METHOD_STRAIGHT_LINE,
                'residual_value_type' => FixedAssetCategory::RESIDUAL_NONE,
                'is_active' => true,
            ]),
        ]));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed asset categories')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request, $creatorId);
        $data['is_active'] = $request->boolean('is_active');

        try {
            $category = $this->service->createCategory($data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-categories.index')
            ->with('success', __('Asset category :code successfully created.', ['code' => $category->code]));
    }

    public function edit(FixedAssetCategory $category)
    {
        if (! Auth::user()->can('manage fixed asset categories')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($category, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('fixed-assets.categories.form', array_merge($this->formData($creatorId), [
            'category' => $category,
        ]));
    }

    public function update(Request $request, FixedAssetCategory $category)
    {
        if (! Auth::user()->can('manage fixed asset categories')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($category, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request, $creatorId, $category);
        $data['is_active'] = $request->boolean('is_active');

        try {
            $category = $this->service->updateCategory($category, $data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-categories.index')
            ->with('success', __('Asset category :code successfully updated.', ['code' => $category->code]));
    }

    public function deactivate(FixedAssetCategory $category)
    {
        if (! Auth::user()->can('manage fixed asset categories')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->deactivateCategory($category, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-categories.index')
            ->with('success', __('Asset category :code deactivated.', ['code' => $category->code]));
    }

    protected function validateForm(Request $request, int $creatorId, ?FixedAssetCategory $category = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer'],
            'classification' => ['required', Rule::in(FixedAssetCategory::CLASSIFICATIONS)],
            'default_useful_life' => ['nullable', 'integer', 'min:1'],
            'default_life_unit' => ['required', Rule::in(FixedAssetCategory::LIFE_UNITS)],
            'depreciation_method' => ['required', Rule::in(FixedAssetCategory::METHODS)],
            'depreciation_rate' => ['nullable', 'numeric', 'min:0'],
            'residual_value_type' => ['required', Rule::in(FixedAssetCategory::RESIDUAL_TYPES)],
            'residual_value_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'capitalization_threshold' => ['nullable', 'numeric', 'min:0'],
            'asset_account_id' => ['nullable', 'integer'],
            'accum_depreciation_account_id' => ['nullable', 'integer'],
            'depreciation_expense_account_id' => ['nullable', 'integer'],
            'impairment_account_id' => ['nullable', 'integer'],
            'disposal_gain_account_id' => ['nullable', 'integer'],
            'disposal_loss_account_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    protected function formData(int $creatorId): array
    {
        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);
        $accounts = ChartOfAccount::where('created_by', $creatorId)->orderBy('code')->get(['id', 'code', 'name']);

        return compact('branches', 'accounts');
    }
}
