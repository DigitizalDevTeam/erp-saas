<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\PosShift;
use Illuminate\Http\Request;

/**
 * Phase 1: Production screen for Kitchen/Bar – view orders by zone and update line status.
 */
class PosProductionController extends Controller
{
    public function index(Request $request)
    {
        if (!\Auth::user()->canManagePos() && !\Auth::user()->can('manage pos production')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $user = \Auth::user();
        $creatorId = $user->creatorId();
        $user->loadMissing('branches');
        $restaurantPool = Branch::where('created_by', $creatorId)
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->pluck('id')
            ->toArray();
        $allowedRestaurantIds = $user->filterBranchIdsForPosAccess($restaurantPool);
        $branches = Branch::whereIn('id', $allowedRestaurantIds)
            ->orderBy('name')
            ->get();

        $showZoneSelector = $user->can('manage pos');
        $showBranchSelector = $showZoneSelector || $branches->count() > 1;

        $branchId = $request->get('branch_id');
        $zone = $request->get('zone', PosProduct::PRINT_ZONE_KITCHEN);
        $warehouseId = $request->get('warehouse_id');

        if (! $showZoneSelector) {
            if ($user->can('manage pos production')) {
                $roleNames = $user->roles->pluck('name')->toArray();
                if (in_array(\App\Services\PosRestaurantRoleService::ROLE_BAR, $roleNames)) {
                    $zone = PosProduct::PRINT_ZONE_BAR;
                } else {
                    $zone = PosProduct::PRINT_ZONE_KITCHEN;
                }
            }
        }

        if (! $branchId && $branches->count() === 1) {
            $branchId = $branches->first()->id;
        }
        if (! $branchId && $branches->count() > 1) {
            $branchId = $branches->first()->id;
        }

        if ($branches->isEmpty() || ! $branchId) {
            return redirect()->route('dashboard')->with('error', __('No branch assigned. Please contact admin to assign a branch.'));
        }
        if (! $user->canAccessBranchForPos((int) $branchId, 'restaurant')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $warehouses = \App\Models\warehouse::where('created_by', $creatorId)
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->orderBy('name')
            ->get();
        if ($warehouseId !== null && $warehouseId !== '' && ! $warehouses->contains('id', (int) $warehouseId)) {
            $warehouseId = null;
        }

        $lines = collect();
        if ($branchId) {
            $openShiftIds = PosShift::where('branch_id', $branchId)
                ->where('status', PosShift::STATUS_OPEN)
                ->where('created_by', $creatorId)
                ->pluck('id');

            $lines = PosProduct::with(['pos.table', 'pos.shift', 'product'])
                ->whereHas('pos', function ($q) use ($openShiftIds, $creatorId, $warehouseId) {
                    $q->whereIn('shift_id', $openShiftIds)
                        ->where('created_by', $creatorId)
                        ->whereNull('cancelled_at');
                    if ($warehouseId) {
                        $q->where('warehouse_id', (int) $warehouseId);
                    }
                })
                ->forProductionZone($zone)
                ->pendingOrInProgress()
                ->orderBy('sent_at')
                ->orderBy('pos_id')
                ->get();
        }

        return view('pos_production.index', compact('branches', 'branchId', 'zone', 'lines', 'showBranchSelector', 'showZoneSelector', 'warehouses', 'warehouseId'));
    }

    public function updateLineStatus(Request $request, PosProduct $line)
    {
        if (!\Auth::user()->canManagePos() && !\Auth::user()->can('manage pos production')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'status' => 'required|in:' . PosProduct::STATUS_IN_PREPARATION . ',' . PosProduct::STATUS_READY,
        ]);

        if ($line->pos->created_by != \Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $line->status = $request->status;
        if ($request->status === PosProduct::STATUS_READY) {
            $line->ready_at = $line->ready_at ?? now();
        }
        $line->save();

        return response()->json(['success' => true, 'message' => __('Status updated.'), 'line' => $line->fresh()]);
    }
}
