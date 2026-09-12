<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ProductService;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseLoss;
use App\Models\WarehouseProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseLossController extends Controller
{
    protected function nextWriteOffNumber(int $creatorId): string
    {
        $maxId = (int) (WarehouseLoss::where('created_by', $creatorId)->max('id') ?? 0);
        $next = $maxId + 1;

        return 'WO-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    protected function allowedWarehouseIds()
    {
        $user = \Auth::user();
        if ($user->type !== 'company' && ! empty($user->warehouse_id)) {
            return [(int) $user->warehouse_id];
        }

        return warehouse::where('created_by', $user->creatorId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function issuerBranchName(): string
    {
        $user = \Auth::user();
        $branchId = $user->activeBranchContextId();
        if (! $branchId) {
            $employee = Employee::where('user_id', $user->id)->first();
            $branchId = $employee->branch_id ?? null;
        }
        if (! $branchId) {
            return '';
        }
        $branch = Branch::find($branchId);

        return $branch->name ?? '';
    }

    public function index()
    {
        $creatorId = \Auth::user()->creatorId();
        $lines = WarehouseLoss::where('created_by', $creatorId)
            ->with(['warehouse', 'product'])
            ->latest()
            ->get();

        $documents = $lines->groupBy(function ($r) {
            return $r->write_off_no ?: ('WO-' . $r->id);
        })->map(function ($group) {
            $first = $group->first();
            $productNames = $group->map(fn ($r) => $r->product->name ?? '-')->filter()->values();
            $qtySum = $group->sum(fn ($r) => (float) $r->quantity);

            return (object) [
                'write_off_no' => $first->write_off_no ?: ('WO-' . $first->id),
                'print_id' => $first->id,
                'date' => $first->date,
                'warehouse' => $first->warehouse,
                'product_label' => $productNames->count() <= 1
                    ? ($productNames->first() ?? '-')
                    : ($productNames->first() . ' +' . ($productNames->count() - 1)),
                'line_count' => $group->count(),
                'quantity' => $qtySum,
                'reason' => $first->reason,
                'issuer_name' => $first->issuer_name,
                'authorized_by' => $first->authorized_by,
                'witness_name' => $first->witness_name,
                'delivery_note_required' => $first->delivery_note_required,
                'delivery_destination' => $first->delivery_destination,
            ];
        })->values();

        $warehouses = warehouse::where('created_by', $creatorId)
            ->whereIn('id', $this->allowedWarehouseIds())
            ->pluck('name', 'id');
        $products = ProductService::where('created_by', $creatorId)
            ->whereIn('type', ['product', 'kit'])
            ->pluck('name', 'id');
        $issuerBranchName = $this->issuerBranchName();

        return view('warehouse-loss.index', compact('documents', 'warehouses', 'products', 'issuerBranchName'));
    }

    public function store(Request $request)
    {
        if (! \Auth::user()->can('create warehouse')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'warehouse_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'date' => 'nullable|date',
            'issuer_name' => 'required|string|max:150',
            'authorized_by' => 'required|string|max:150',
            'witness_name' => 'required|string|max:150',
            'delivery_destination' => 'nullable|string|max:255',
        ]);

        $warehouseId = (int) $request->warehouse_id;
        $items = [];
        foreach ($request->input('items', []) as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (! isset($items[$pid])) {
                $items[$pid] = 0.0;
            }
            $items[$pid] += $qty;
        }

        if ($items === []) {
            return redirect()->back()->withInput()->with('error', __('Add at least one product line.'));
        }

        foreach ($items as $productId => $qty) {
            $warehouseProduct = WarehouseProduct::where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();
            if (! $warehouseProduct || (float) $warehouseProduct->quantity < $qty) {
                $name = ProductService::find($productId)->name ?? ('#' . $productId);

                return redirect()->back()->withInput()->with('error', __('Insufficient warehouse stock for this loss.') . ' (' . $name . ')');
            }
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $writeOffNo = $this->nextWriteOffNumber($creatorId);
        $date = $request->date ?: date('Y-m-d');

        DB::transaction(function () use ($request, $items, $warehouseId, $writeOffNo, $date, $creatorId) {
            foreach ($items as $productId => $qty) {
                WarehouseLoss::create([
                    'write_off_no' => $writeOffNo,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'date' => $date,
                    'reason' => $request->reason,
                    'issuer_name' => $request->issuer_name,
                    'authorized_by' => $request->authorized_by,
                    'witness_name' => $request->witness_name,
                    'delivery_note_required' => $request->boolean('delivery_note_required'),
                    'delivery_destination' => $request->delivery_destination,
                    'created_by' => $creatorId,
                ]);

                Utility::warehouse_quantity('minus', $qty, $productId, $warehouseId);
                Utility::total_quantity('minus', $qty, $productId);
                Utility::addProductStock($productId, $qty, 'warehouse_loss', __('Stock loss/damage write-off'), 0);
            }
        });

        return redirect()->route('warehouse-loss.index')->with('success', __('Warehouse loss recorded successfully.'));
    }

    public function print($id)
    {
        $document = $this->buildWriteOffDocument((int) $id);
        if ($document === null) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('warehouse-loss.print', $document);
    }

    public function printBulk(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        if (empty($ids)) {
            return redirect()->route('warehouse-loss.index')->with('error', __('Please select at least one loss record to print.'));
        }

        $documents = [];
        $seen = [];
        foreach ($ids as $id) {
            $document = $this->buildWriteOffDocument($id);
            if ($document === null) {
                continue;
            }
            $key = $document['writeOffNo'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $documents[] = $document;
        }

        if (empty($documents)) {
            return redirect()->route('warehouse-loss.index')->with('error', __('No loss records found to print.'));
        }

        return view('warehouse-loss.print-bulk', compact('documents'));
    }

    /**
     * @return array{record: WarehouseLoss, lines: \Illuminate\Support\Collection, writeOffNo: string}|null
     */
    protected function buildWriteOffDocument(int $id): ?array
    {
        $record = WarehouseLoss::with(['warehouse', 'product'])->find($id);
        if (! $record || (int) $record->created_by !== (int) \Auth::user()->creatorId()) {
            return null;
        }

        $writeOffNo = $record->write_off_no ?: ('WO-' . $record->id);
        $lines = WarehouseLoss::with(['warehouse', 'product'])
            ->where('created_by', $record->created_by)
            ->where(function ($q) use ($record) {
                if (! empty($record->write_off_no)) {
                    $q->where('write_off_no', $record->write_off_no);
                } else {
                    $q->where('id', $record->id);
                }
            })
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            $lines = collect([$record]);
        }

        return compact('record', 'lines', 'writeOffNo');
    }
}
