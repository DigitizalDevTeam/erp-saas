<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Employee;
use App\Models\ProductService;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseLoss;
use App\Models\WarehouseProduct;
use App\Services\Accounting\JournalEntryPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($user->type !== 'company' && ($assigned = $user->assignedWarehouseIds()) !== []) {
            return $assigned;
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
            $costSum = $group->sum(fn ($r) => (float) ($r->cost_of_goods ?? 0));

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
                'cost_of_goods' => $costSum,
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

        $productRows = ProductService::where('created_by', $creatorId)
            ->whereIn('type', ['product', 'kit'])
            ->orderBy('name')
            ->get(['id', 'name', 'purchase_price']);

        $products = $productRows->pluck('name', 'id');
        $productCosts = $productRows->mapWithKeys(function ($p) {
            return [(string) $p->id => (float) ($p->purchase_price ?? 0)];
        });

        $issuerBranchName = $this->issuerBranchName();

        return view('warehouse-loss.index', compact('documents', 'warehouses', 'products', 'productCosts', 'issuerBranchName'));
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
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'date' => 'nullable|date',
            'issuer_name' => 'required|string|max:150',
            'authorized_by' => 'required|string|max:150',
            'witness_name' => 'required|string|max:150',
            'delivery_destination' => 'nullable|string|max:255',
        ]);

        $warehouseId = (int) $request->warehouse_id;
        $lines = [];
        $qtyByProduct = [];

        foreach ($request->input('items', []) as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }

            $product = ProductService::where('created_by', \Auth::user()->creatorId())->find($pid);
            if (! $product) {
                continue;
            }

            $unitCost = array_key_exists('unit_cost', $row) && $row['unit_cost'] !== null && $row['unit_cost'] !== ''
                ? (float) $row['unit_cost']
                : (float) ($product->purchase_price ?? 0);
            $unitCost = max(0, round($unitCost, 4));
            $lineCost = round($qty * $unitCost, 2);

            $lines[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'cost_of_goods' => $lineCost,
            ];
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
        }

        if ($lines === []) {
            return redirect()->back()->withInput()->with('error', __('Add at least one product line.'));
        }

        foreach ($qtyByProduct as $productId => $qty) {
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
        $totalCost = array_sum(array_column($lines, 'cost_of_goods'));

        DB::transaction(function () use ($request, $lines, $warehouseId, $writeOffNo, $date, $creatorId, $totalCost) {
            foreach ($lines as $line) {
                $loss = WarehouseLoss::create([
                    'write_off_no' => $writeOffNo,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'cost_of_goods' => $line['cost_of_goods'],
                    'date' => $date,
                    'reason' => $request->reason,
                    'issuer_name' => $request->issuer_name,
                    'authorized_by' => $request->authorized_by,
                    'witness_name' => $request->witness_name,
                    'delivery_note_required' => $request->boolean('delivery_note_required'),
                    'delivery_destination' => $request->delivery_destination,
                    'created_by' => $creatorId,
                ]);

                Utility::warehouse_quantity('minus', $line['quantity'], $line['product_id'], $warehouseId);
                Utility::total_quantity('minus', $line['quantity'], $line['product_id']);
                Utility::addProductStock(
                    $line['product_id'],
                    $line['quantity'],
                    'warehouse_loss',
                    __('Stock loss/damage write-off'),
                    $loss->id,
                    $loss->id
                );
            }

            $this->postLossJournal($creatorId, $date, $writeOffNo, (float) $totalCost, (string) ($request->reason ?? ''));
        });

        return redirect()->route('warehouse-loss.index')->with('success', __('Warehouse loss recorded successfully.'));
    }

    /**
     * Debit Breakage (5025) / Credit Inventory (1510) so accounting can book the loss.
     */
    protected function postLossJournal(int $creatorId, string $date, string $writeOffNo, float $totalCost, string $reason): void
    {
        if ($totalCost <= 0) {
            return;
        }

        $expenseAccount = ChartOfAccount::where('created_by', $creatorId)->where('code', '5025')->orderBy('id')->first();
        $inventoryAccount = ChartOfAccount::where('created_by', $creatorId)->where('code', '1510')->orderBy('id')->first();

        if (! $expenseAccount || ! $inventoryAccount) {
            Log::warning('Warehouse loss journal skipped: missing chart accounts 5025/1510', [
                'created_by' => $creatorId,
                'write_off_no' => $writeOffNo,
            ]);

            return;
        }

        $description = trim(__('Warehouse write-off') . ' ' . $writeOffNo . ($reason !== '' ? ' — ' . $reason : ''));

        try {
            app(JournalEntryPoster::class)->post(
                $creatorId,
                $date,
                $writeOffNo,
                $description,
                [
                    [
                        'account' => (int) $expenseAccount->id,
                        'debit' => $totalCost,
                        'credit' => 0,
                        'description' => $description,
                    ],
                    [
                        'account' => (int) $inventoryAccount->id,
                        'debit' => 0,
                        'credit' => $totalCost,
                        'description' => $description,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Warehouse loss journal failed: ' . $e->getMessage(), [
                'write_off_no' => $writeOffNo,
            ]);
            throw $e;
        }
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
     * Unit cost on warehouse loss is stored exclusive of VAT (purchase cost).
     * Print/display adds product tax_id rate for "with VAT" columns.
     *
     * @return array{0: float, 1: float, 2: float} [taxRate%, unitIncVat, lineIncVat]
     */
    protected function costWithVatForLine(WarehouseLoss $line): array
    {
        $qty = (float) ($line->quantity ?? 0);
        $unitEx = (float) ($line->unit_cost ?? 0);
        if ($unitEx <= 0 && $line->product) {
            $unitEx = (float) ($line->product->purchase_price ?? 0);
        }
        $lineEx = (float) ($line->cost_of_goods ?? 0);
        if ($lineEx <= 0) {
            $lineEx = round($qty * $unitEx, 2);
        }

        $taxRate = 0.0;
        if ($line->product && ! empty($line->product->tax_id)) {
            $taxRate = (float) Utility::totalTaxRate((string) $line->product->tax_id);
        }

        $unitInc = $taxRate > 0 ? round($unitEx * (1 + ($taxRate / 100)), 4) : round($unitEx, 4);
        $lineInc = $taxRate > 0 ? round($lineEx * (1 + ($taxRate / 100)), 2) : round($lineEx, 2);

        return [$taxRate, $unitInc, $lineInc];
    }

    /**
     * @return array{record: WarehouseLoss, lines: \Illuminate\Support\Collection, writeOffNo: string, totalCost: float, totalCostWithVat: float}|null
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

        $totalCost = 0.0;
        $totalCostWithVat = 0.0;
        foreach ($lines as $line) {
            [$taxRate, $unitInc, $lineInc] = $this->costWithVatForLine($line);
            $line->tax_rate = $taxRate;
            $line->unit_cost_with_vat = $unitInc;
            $line->cost_of_goods_with_vat = $lineInc;
            $totalCost += (float) ($line->cost_of_goods ?? 0);
            $totalCostWithVat += $lineInc;
        }
        $totalCost = round($totalCost, 2);
        $totalCostWithVat = round($totalCostWithVat, 2);

        return compact('record', 'lines', 'writeOffNo', 'totalCost', 'totalCostWithVat');
    }
}
