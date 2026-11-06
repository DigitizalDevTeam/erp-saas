<?php

namespace App\Http\Controllers;

use App\Models\KitchenProductionBatch;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Kitchen Production — Kitchen Stock (Phase 1).
 *
 * Read-only view of the prepared batches received into kitchen stock:
 * what was produced, what is left, and when it expires. A batch is
 * consumed FEFO by dish sales (Phase 2); this screen shows the
 * reconciliation produced - sold - lost = remaining (plan §9).
 */
class KitchenStockController extends Controller
{
    public function index(Request $request)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $batches = KitchenProductionBatch::where('created_by', $creatorId)
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->input('warehouse_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('batch_number', 'like', $search)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $search));
                });
            })
            ->with('product', 'warehouse', 'order.recipe')
            ->orderBy('expiry_date')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('kitchen.stock.index', [
            'batches' => $batches,
            'warehouses' => warehouse::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(KitchenProductionBatch $batch)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $batch->created_by !== (int) Auth::user()->creatorId()) {
            abort(403, __('This batch belongs to another company.'));
        }

        $batch->load('product', 'warehouse', 'order.recipe', 'order.lines.ingredient', 'order.lines.lotUsages.lot');

        return view('kitchen.stock.show', compact('batch'));
    }
}
