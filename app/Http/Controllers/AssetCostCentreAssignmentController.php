<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Models\AssetCostCentreAssignment;
use App\Models\CostCentre;
use App\Models\FixedAsset;
use App\Services\AssetAssignmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Asset cost-centre assignments (Cost Centre Phase 2, plan §5/§6/§10).
 *
 * Effective-dated splits of a fixed asset across cost centres:
 * assign a generation of rows summing to exactly 100% from a
 * start date (superseding the open generation — history is never
 * rewritten), or end the open generation. Every change is audited
 * in dimension_audit_events by the service. The depreciation
 * engine reads these rows for the period split (plan §7, D4).
 */
class AssetCostCentreAssignmentController extends Controller
{
    public function __construct(
        protected AssetAssignmentService $assignments = new AssetAssignmentService(),
    ) {}

    /** Standalone assignments list (all assets, filterable). */
    public function index(Request $request)
    {
        if (! Auth::user()->can('view cost centres')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();

        $rows = collect();
        $assets = collect();
        $centres = collect();
        if (Schema::hasTable('asset_cost_centre_assignments')) {
            $query = AssetCostCentreAssignment::forCompany($creatorId)
                ->with(['asset', 'costCentre'])
                ->when($request->filled('asset_id'), fn ($q) => $q->where('asset_id', (int) $request->input('asset_id')))
                ->when($request->filled('cost_centre_id'), fn ($q) => $q->where('cost_centre_id', (int) $request->input('cost_centre_id')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $term = '%'.$request->input('search').'%';
                    $q->whereHas('asset', fn ($a) => $a->where('asset_code', 'like', $term)->orWhere('name', 'like', $term));
                })
                ->orderByDesc('valid_from')
                ->orderByDesc('id');

            $rows = $query->paginate(20)->withQueryString();
        }

        if (Schema::hasTable('fixed_assets')) {
            $assets = FixedAsset::forCompany($creatorId)->orderBy('asset_code')->get(['id', 'asset_code', 'name']);
        }
        $centres = CostCentre::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);

        return view('cost-centres.asset-assignments.index', compact('rows', 'assets', 'centres'));
    }

    /** New assignment form (asset + start date + split rows). */
    public function create(Request $request)
    {
        if (! Auth::user()->can('manage asset cost centre assignments')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $assets = Schema::hasTable('fixed_assets')
            ? FixedAsset::forCompany($creatorId)->orderBy('asset_code')->get(['id', 'asset_code', 'name', 'status'])
            : collect();
        $centres = CostCentre::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);
        $selectedAsset = null;
        if ($request->filled('asset_id') && Schema::hasTable('fixed_assets')) {
            $selectedAsset = FixedAsset::forCompany($creatorId)->find((int) $request->input('asset_id'));
        }

        return view('cost-centres.asset-assignments.create', compact('assets', 'centres', 'selectedAsset'));
    }

    /** Store a new assignment generation. */
    public function store(Request $request)
    {
        if (! Auth::user()->can('manage asset cost centre assignments')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();

        $validated = $request->validate([
            'asset_id' => ['required', 'integer'],
            'valid_from' => ['required', 'date'],
            'splits' => ['required', 'array', 'min:1'],
            'splits.*.cost_centre_id' => ['required', 'integer'],
            'splits.*.percentage' => ['required', 'numeric', 'min:0.000001', 'max:100'],
        ]);

        $asset = FixedAsset::forCompany($creatorId)->find((int) $validated['asset_id']);
        if (! $asset) {
            return redirect()->back()->withInput()->with('error', __('The selected asset does not exist for this company.'));
        }

        try {
            $this->assignments->assign(
                $asset,
                $validated['splits'],
                Carbon::parse($validated['valid_from']),
                $creatorId,
                (int) Auth::id()
            );
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('asset-cost-centre-assignments.show', $asset->id)
            ->with('success', __('Cost centre assignment saved.'));
    }

    /** One asset's full assignment history + current split. */
    public function show(int $assetId)
    {
        if (! Auth::user()->can('view cost centres')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $asset = FixedAsset::forCompany($creatorId)->find($assetId);
        if (! $asset) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $history = $this->assignments->history($asset, $creatorId);
        $current = $this->assignments->currentSplit($asset, $creatorId);

        return view('cost-centres.asset-assignments.show', compact('asset', 'history', 'current'));
    }

    /** End the asset's open assignment generation on a date. */
    public function end(Request $request, int $assetId)
    {
        if (! Auth::user()->can('manage asset cost centre assignments')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $asset = FixedAsset::forCompany($creatorId)->find($assetId);
        if (! $asset) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $validated = $request->validate([
            'valid_to' => ['required', 'date'],
        ]);

        try {
            $closed = $this->assignments->endAssignment(
                $asset,
                Carbon::parse($validated['valid_to']),
                $creatorId,
                (int) Auth::id()
            );
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('asset-cost-centre-assignments.show', $asset->id)
            ->with('success', __('Assignment ended (:count row(s) closed). Depreciation after :date falls back to the asset\'s own cost centre.', [
                'count' => $closed,
                'date' => $validated['valid_to'],
            ]));
    }
}
