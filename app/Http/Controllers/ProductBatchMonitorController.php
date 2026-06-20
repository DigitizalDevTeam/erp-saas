<?php

namespace App\Http\Controllers;

use App\Models\ProductService;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductBatchMonitorController extends Controller
{
    public function index(Request $request)
    {
        if (! \Auth::user()->can('manage product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $q = DB::table('product_expiry_lots as l')
            ->join('product_services as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.created_by', $creatorId)
            ->where('p.created_by', $creatorId);

        if ($request->filled('product_id') && (int) $request->product_id > 0) {
            $q->where('l.product_id', (int) $request->product_id);
        }

        if ($request->filled('search')) {
            $raw = trim((string) $request->search);
            if ($raw !== '') {
                $term = '%'.$raw.'%';
                $q->where(function ($w) use ($term) {
                    $w->where('p.name', 'like', $term)
                        ->orWhere('p.sku', 'like', $term)
                        ->orWhere('l.batch_no', 'like', $term);
                });
            }
        }

        if ($request->filled('batch_no')) {
            $bn = trim((string) $request->batch_no);
            if ($bn !== '') {
                $q->where('l.batch_no', 'like', '%'.$bn.'%');
            }
        }

        if ($request->filled('warehouse_filter')) {
            $wf = $request->warehouse_filter;
            if ($wf === 'unassigned') {
                $q->whereNull('l.warehouse_id');
            } elseif ((int) $wf > 0) {
                $q->where('l.warehouse_id', (int) $wf);
            }
        }

        if ($request->filled('source') && in_array($request->source, ['purchase', 'manual'], true)) {
            $q->where('l.source', $request->source);
        }

        $includeNoExpiry = $request->boolean('include_no_expiry');

        if ($request->filled('expiry_from') && $request->filled('expiry_to')) {
            if ($includeNoExpiry) {
                $q->where(function ($w) use ($request) {
                    $w->whereNull('l.expiry_date')
                        ->orWhereBetween('l.expiry_date', [$request->expiry_from, $request->expiry_to]);
                });
            } else {
                $q->whereBetween('l.expiry_date', [$request->expiry_from, $request->expiry_to]);
            }
        } elseif ($request->filled('expiry_from')) {
            if ($includeNoExpiry) {
                $q->where(function ($w) use ($request) {
                    $w->whereNull('l.expiry_date')
                        ->orWhere('l.expiry_date', '>=', $request->expiry_from);
                });
            } else {
                $q->where('l.expiry_date', '>=', $request->expiry_from);
            }
        } elseif ($request->filled('expiry_to')) {
            if ($includeNoExpiry) {
                $q->where(function ($w) use ($request) {
                    $w->whereNull('l.expiry_date')
                        ->orWhere('l.expiry_date', '<=', $request->expiry_to);
                });
            } else {
                $q->where('l.expiry_date', '<=', $request->expiry_to);
            }
        }

        if ($request->filled('mfg_from')) {
            $q->whereNotNull('l.mfg_date')->where('l.mfg_date', '>=', $request->mfg_from);
        }
        if ($request->filled('mfg_to')) {
            $q->whereNotNull('l.mfg_date')->where('l.mfg_date', '<=', $request->mfg_to);
        }

        $remaining = $request->input('remaining', 'with_stock');
        if ($remaining === 'with_stock') {
            $q->where('l.quantity_remaining', '>', 0);
        } elseif ($remaining === 'depleted') {
            $q->where('l.quantity_remaining', '<=', 0);
        }

        $today = now()->toDateString();
        $expiryStatus = $request->input('expiry_status', 'all');
        if ($expiryStatus === 'expired') {
            $q->whereNotNull('l.expiry_date')->where('l.expiry_date', '<', $today);
        } elseif ($expiryStatus === 'valid') {
            $q->where(function ($w) use ($today) {
                $w->whereNull('l.expiry_date')->orWhere('l.expiry_date', '>=', $today);
            });
        } elseif ($expiryStatus === 'expiring_soon') {
            $days = max(1, min(365, (int) $request->input('expiring_days', 30)));
            $until = now()->addDays($days)->toDateString();
            $q->whereNotNull('l.expiry_date')
                ->where('l.expiry_date', '>=', $today)
                ->where('l.expiry_date', '<=', $until);
        }

        if ($request->boolean('purchase_only')) {
            $q->whereNotNull('l.purchase_id');
        }

        $sort = $request->input('sort', 'expiry_asc');
        switch ($sort) {
            case 'expiry_desc':
                $q->orderByRaw('l.expiry_date IS NULL ASC')->orderBy('l.expiry_date', 'desc')->orderBy('l.id', 'desc');
                break;
            case 'created_desc':
                $q->orderBy('l.created_at', 'desc')->orderBy('l.id', 'desc');
                break;
            case 'remaining_asc':
                $q->orderBy('l.quantity_remaining', 'asc')->orderBy('l.id', 'desc');
                break;
            case 'product_asc':
                $q->orderBy('p.name', 'asc')->orderBy('l.id', 'desc');
                break;
            default:
                $q->orderByRaw('l.expiry_date IS NULL ASC')->orderBy('l.expiry_date', 'asc')->orderBy('l.id', 'asc');
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        $lots = $q->select([
            'l.id',
            'l.product_id',
            'l.warehouse_id',
            'l.purchase_id',
            'l.purchase_product_id',
            'l.source',
            'l.batch_no',
            'l.mfg_date',
            'l.expiry_date',
            'l.quantity_in',
            'l.quantity_remaining',
            'l.created_at',
            'p.name as product_name',
            'p.sku as product_sku',
            'p.batch_tracking',
            'p.batch_consumption_method',
            'w.name as warehouse_name',
        ])->paginate($perPage)->withQueryString();

        $products = ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        return view('productbatch.index', compact('lots', 'products', 'warehouses'));
    }
}
