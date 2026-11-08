<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetLocation;
use App\Services\FixedAssetService;
use App\Services\FixedAssetSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Asset Register — Phase 0: read-only foundation
 * (list + profile + label print). The registration /
 * acquisition / capitalization workflow is Phase 1, so no
 * asset is created or edited through a screen yet.
 */
class FixedAssetController extends Controller
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

        $assets = FixedAsset::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)
                        ->orWhere('code', 'like', $search)
                        ->orWhere('serial_number', 'like', $search);
                });
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->input('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->input('location_id')))
            ->with(['category', 'location', 'costCentre'])
            ->orderBy('code')
            ->paginate(20);

        $categories = FixedAssetCategory::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);
        $locations = FixedAssetLocation::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);

        return view('fixed-assets.index', compact('assets', 'categories', 'locations'));
    }

    public function show(FixedAsset $asset)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($asset, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $asset->load(['category', 'location', 'branch', 'department', 'costCentre', 'custodian', 'parent', 'components', 'documents', 'approvals', 'statusHistory', 'auditEvents']);

        return view('fixed-assets.show', compact('asset'));
    }

    /**
     * Printable asset label (spec §10): company name, asset
     * name, code, QR code, category, department, location and
     * custodian. Scanning the QR opens the asset profile
     * (subject to permission — the show route requires auth).
     * Label size comes from the company settings (D8).
     */
    public function label(FixedAsset $asset)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->assertSameCompany($asset, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $asset->load(['category', 'location', 'department', 'costCentre', 'custodian']);

        $dimensions = FixedAssetSettings::labelDimensions($creatorId);
        $profileUrl = route('fixed-assets.show', $asset);

        return view('fixed-assets.label', compact('asset', 'dimensions', 'profileUrl'));
    }
}
