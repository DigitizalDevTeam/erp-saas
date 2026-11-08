<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\Branch;
use App\Models\FixedAssetLocation;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Asset Locations — Phase 0: master data only (list,
 * form, deactivate). Location = Branch + Asset Location
 * (plan §5).
 */
class FixedAssetLocationController extends Controller
{
    public function __construct(
        protected FixedAssetService $service,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $locations = FixedAssetLocation::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)->orWhere('code', 'like', $search);
                });
            })
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->with('branch')
            ->orderBy('code')
            ->paginate(20);

        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        return view('fixed-assets.locations.index', compact('locations', 'branches'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        return view('fixed-assets.locations.form', [
            'location' => new FixedAssetLocation(['is_active' => true]),
            'branches' => $branches,
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request);
        $data['is_active'] = $request->boolean('is_active');

        try {
            $location = $this->service->createLocation($data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-locations.index')
            ->with('success', __('Asset location :code successfully created.', ['code' => $location->code]));
    }

    public function edit(FixedAssetLocation $location)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($location, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        return view('fixed-assets.locations.form', compact('location', 'branches'));
    }

    public function update(Request $request, FixedAssetLocation $location)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($location, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateForm($request);
        $data['is_active'] = $request->boolean('is_active');

        try {
            $location = $this->service->updateLocation($location, $data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-locations.index')
            ->with('success', __('Asset location :code successfully updated.', ['code' => $location->code]));
    }

    public function deactivate(FixedAssetLocation $location)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->deactivateLocation($location, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-locations.index')
            ->with('success', __('Asset location :code deactivated.', ['code' => $location->code]));
    }

    protected function validateForm(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'room' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
