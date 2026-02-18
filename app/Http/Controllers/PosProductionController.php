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
        if (!\Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();
        $branchId = $request->get('branch_id');
        $zone = $request->get('zone', PosProduct::PRINT_ZONE_KITCHEN);

        $branches = Branch::where('created_by', $creatorId)
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->orderBy('name')
            ->get();

        if (!$branchId && $branches->isNotEmpty()) {
            $branchId = $branches->first()->id;
        }

        $lines = collect();
        if ($branchId) {
            $openShiftIds = PosShift::where('branch_id', $branchId)
                ->where('status', PosShift::STATUS_OPEN)
                ->where('created_by', $creatorId)
                ->pluck('id');

            $lines = PosProduct::with(['pos.table', 'pos.shift', 'product'])
                ->whereHas('pos', function ($q) use ($openShiftIds, $creatorId) {
                    $q->whereIn('shift_id', $openShiftIds)
                        ->where('created_by', $creatorId)
                        ->whereNull('cancelled_at');
                })
                ->forProductionZone($zone)
                ->pendingOrInProgress()
                ->orderBy('sent_at')
                ->orderBy('pos_id')
                ->get();
        }

        return view('pos_production.index', compact('branches', 'branchId', 'zone', 'lines'));
    }

    public function updateLineStatus(Request $request, PosProduct $line)
    {
        if (!\Auth::user()->canManagePos()) {
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
