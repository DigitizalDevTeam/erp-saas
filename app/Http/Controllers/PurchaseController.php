<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\CustomField;
use App\Models\Proforma;
use App\Models\StockReport;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Purchase;
use App\Models\PurchaseQuotation;
use App\Models\Quotation;
use App\Models\BillProduct;
use App\Models\ProductIdentifier;
use App\Models\ProductExpiryLot;
use App\Models\ProductVariant;
use App\Models\PurchaseProduct;
use App\Models\PurchasePayment;
use App\Models\Transaction;
use App\Models\Vender;
use App\Models\User;
use App\Models\Utility;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use App\Services\DocumentLayoutRenderer;
use Illuminate\Support\Facades\Crypt;
use App\Models\warehouse;
use App\Support\SalesPurchaseNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurchaseController extends Controller
{
    /**
     * One repeater row may expand to several purchase lines when variant_quantities[] is posted.
     *
     * @return array{lines?: array<int, array<string, mixed>>, error?: string}
     */
    protected function expandRepeaterPurchaseItem(array $row, int $creatorId): array
    {
        $productId = (int) ($row['item'] ?? 0);
        if ($productId <= 0) {
            return ['error' => __('Invalid product.')];
        }
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return ['error' => __('Invalid product.')];
        }

        $variantQtys = [];
        if (! empty($row['variant_quantities']) && is_array($row['variant_quantities'])) {
            foreach ($row['variant_quantities'] as $vid => $q) {
                $q = (float) $q;
                if ($q > 0) {
                    $variantQtys[(int) $vid] = $q;
                }
            }
        }

        if ($product->variants_enabled ?? false) {
            if (count($variantQtys) > 0) {
                if (($product->identifier_tracking ?? false) && count($variantQtys) > 1) {
                    return ['error' => __('For products with Serial/MAC tracking, use one variant per row or a single quantity per variant.')];
                }
                $lines = [];
                $baseDiscount = (float) ($row['discount'] ?? 0);
                $totalVal = 0.0;
                $meta = [];
                foreach ($variantQtys as $vid => $qty) {
                    $vr = ProductVariant::where('id', $vid)->where('product_service_id', $product->id)->where('created_by', $creatorId)->first();
                    if (! $vr) {
                        return ['error' => __('Invalid variant for this product.')];
                    }
                    $pp = $vr->purchase_price !== null && (float) $vr->purchase_price >= 0
                        ? (float) $vr->purchase_price
                        : (float) $product->purchase_price;
                    $meta[] = ['vid' => $vid, 'qty' => $qty, 'price' => $pp, 'label' => $vr->displayLabel($product)];
                    $totalVal += $qty * $pp;
                }
                foreach ($meta as $m) {
                    $line = $row;
                    $line['quantity'] = $m['qty'];
                    $line['product_variant_id'] = $m['vid'];
                    $line['price'] = $m['price'];
                    $line['discount'] = $totalVal > 0
                        ? round($baseDiscount * (($m['qty'] * $m['price']) / $totalVal), 4)
                        : round($baseDiscount / max(1, count($meta)), 4);
                    unset($line['variant_quantities']);
                    $line['_variant_label'] = $m['label'];
                    $lines[] = $line;
                }

                return ['lines' => $lines];
            }

            $variantId = isset($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;
            if ($variantId !== null && $variantId <= 0) {
                $variantId = null;
            }
            if (! $variantId) {
                // Quotation/proforma converts have no variant UI — use first variant when product is variant-enabled.
                if (! empty($row['allow_default_variant'])) {
                    $firstVariant = ProductVariant::where('product_service_id', $product->id)
                        ->where('created_by', $creatorId)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first();
                    if ($firstVariant) {
                        $variantId = (int) $firstVariant->id;
                        $row['product_variant_id'] = $variantId;
                    } else {
                        // Marked as variant product but no variants defined — allow base line.
                        $row['product_variant_id'] = null;
                        $one = $row;
                        unset($one['variant_quantities'], $one['allow_default_variant']);

                        return ['lines' => [$one]];
                    }
                } else {
                    return ['error' => __('Enter quantity for at least one variant, or select a variant.')];
                }
            }
            if (! ProductVariant::where('id', $variantId)->where('product_service_id', $product->id)->where('created_by', $creatorId)->exists()) {
                return ['error' => __('Invalid variant for this product.')];
            }
        } else {
            $row['product_variant_id'] = null;
        }

        $one = $row;
        unset($one['variant_quantities']);
        if (! ($product->variants_enabled ?? false)) {
            $one['product_variant_id'] = null;
        }

        return ['lines' => [$one]];
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        $vender = Vender::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
        $vender->prepend(__('Select Supplier'), '');
        $status = Purchase::$statues;
        $purchases = Purchase::where('created_by', '=', \Auth::user()->creatorId())->with(['vender','category'])->get();


        return view('purchase.index', compact('purchases', 'status','vender'));


    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($vendorId = 0)
    {
        if(\Auth::user()->can('create purchase'))
        {
            $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'purchase')->get();
            $category     = ProductServiceCategory::where('created_by', \Auth::user()->creatorId())->where('type', 'expense')->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');

            $purchase_number = \Auth::user()->purchaseNumberFormat($this->purchaseNumber());
            $venders     = Vender::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $venders->prepend(__('Select Supplier'), '');

            $warehouse     = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $warehouse->prepend('Select Warehouse', '');

            $product_services = ProductService::where('created_by', \Auth::user()->creatorId())->where('type','!=', 'service')->get()->pluck('name', 'id');
            $product_services->prepend('--', '');

            return view('purchase.create', compact('venders', 'purchase_number', 'product_services', 'category', 'customFields','vendorId','warehouse'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        if(\Auth::user()->can('create purchase'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    'vender_id' => 'required',
                    'warehouse_id' => 'required',
                    'purchase_date' => 'required',
                    'category_id' => 'required',
                    'items' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $purchase                 = new Purchase();
            $purchase->purchase_id    = $this->purchaseNumber();
            $purchase->vender_id      = $request->vender_id;
            $purchase->warehouse_id      = $request->warehouse_id;
            $purchase->purchase_date  = $request->purchase_date;
            $purchase->purchase_number   = !empty($request->purchase_number) ? $request->purchase_number : 0;
            $purchase->status         =  0;
            $purchase->category_id    = $request->category_id;
            $purchase->created_by     = \Auth::user()->creatorId();
            if ($request->filled('purchase_quotation_id')) {
                $purchase->purchase_quotation_id = (int) $request->purchase_quotation_id;
            }
            if ($request->filled('purchase_proforma_id')) {
                $purchase->purchase_proforma_id = (int) $request->purchase_proforma_id;
            }
            if ($request->filled('proforma_id')) {
                $purchase->proforma_id = (int) $request->proforma_id;
            }
            if ($request->filled('quotation_id')) {
                $purchase->quotation_id = (int) $request->quotation_id;
            }
            $purchase->save();

            $products = $request->items;
            $creatorId = \Auth::user()->creatorId();
            $warehouseId = (int) $request->warehouse_id;

            for ($i = 0; $i < count($products); $i++) {
                $expanded = $this->expandRepeaterPurchaseItem($products[$i], (int) $creatorId);
                if (! empty($expanded['error'])) {
                    return redirect()->back()->with('error', $expanded['error'])->withInput();
                }
                foreach ($expanded['lines'] as $line) {
                    $product = ProductService::where('id', $line['item'])->where('created_by', $creatorId)->first();
                    if (! $product) {
                        return redirect()->back()->with('error', __('Invalid product in line items.'))->withInput();
                    }
                    $variantId = isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null;
                    if ($variantId !== null && $variantId <= 0) {
                        $variantId = null;
                    }
                    if (! ($product->variants_enabled ?? false)) {
                        $variantId = null;
                    }
                    if ($product && ($product->batch_tracking ?? false) && ($product->affect_stock ?? true)) {
                        if (trim((string) ($line['batch_no'] ?? '')) === '' || empty($line['expiry_date'] ?? null)) {
                            return redirect()->back()->with('error', __('Batch number and expiry date are required for batch-tracked products.'))->withInput();
                        }
                    }

                    $purchaseProduct = new PurchaseProduct();
                    $purchaseProduct->purchase_id = $purchase->id;
                    $purchaseProduct->product_id = $line['item'];
                    $purchaseProduct->product_variant_id = $variantId;
                    $purchaseProduct->quantity = $line['quantity'];
                    $purchaseProduct->tax = $line['tax'];
                    $purchaseProduct->discount = $line['discount'];
                    $purchaseProduct->price = $line['price'];
                    $purchaseProduct->description = $line['description'];
                    [$pRow, $pShelf] = Utility::normalizeWarehouseLocation($line['row_no'] ?? '', $line['shelf_no'] ?? '');
                    $purchaseProduct->row_no = $pRow !== '' ? $pRow : null;
                    $purchaseProduct->shelf_no = $pShelf !== '' ? $pShelf : null;
                    $purchaseProduct->expiry_date = ! empty($line['expiry_date']) ? $line['expiry_date'] : null;
                    $purchaseProduct->batch_no = ! empty($line['batch_no']) ? trim($line['batch_no']) : null;
                    $purchaseProduct->mfg_date = ! empty($line['mfg_date']) ? $line['mfg_date'] : null;
                    $purchaseProduct->selected_options_json = ! empty($line['selected_options_json'])
                        ? (is_string($line['selected_options_json']) ? $line['selected_options_json'] : json_encode($line['selected_options_json']))
                        : null;
                    $purchaseProduct->save();

                    $createExpiryLot = ($product && ($product->batch_tracking ?? false) && ($product->affect_stock ?? true))
                        || ! empty($purchaseProduct->expiry_date)
                        || ! empty($purchaseProduct->batch_no);
                    if ($createExpiryLot) {
                        ProductExpiryLot::create([
                            'product_id' => (int) $purchaseProduct->product_id,
                            'warehouse_id' => (int) $warehouseId,
                            'purchase_id' => (int) $purchase->id,
                            'purchase_product_id' => (int) $purchaseProduct->id,
                            'source' => 'purchase',
                            'expiry_date' => $purchaseProduct->expiry_date,
                            'batch_no' => $purchaseProduct->batch_no,
                            'mfg_date' => $purchaseProduct->mfg_date,
                            'quantity_in' => (float) $purchaseProduct->quantity,
                            'quantity_remaining' => (float) $purchaseProduct->quantity,
                            'created_by' => (int) $creatorId,
                        ]);
                    }

                    if ($product && ($product->identifier_tracking ?? false)) {
                        $raw = $line['identifiers'] ?? '';
                        $list = preg_split("/\r\n|\n|\r|,|;|\t/", (string) $raw);
                        $list = array_values(array_filter(array_map(function ($v) {
                            $v = trim((string) $v);

                            return $v === '' ? null : $v;
                        }, $list)));

                        $unique = array_values(array_unique($list));
                        if (count($unique) !== count($list)) {
                            return redirect()->back()->with('error', __('Duplicate Serial/MAC found in the list for one of the items.'));
                        }
                        if (count($unique) !== (int) $purchaseProduct->quantity) {
                            return redirect()->back()->with('error', __('Serial/MAC count must match quantity for tracked products.'));
                        }

                        $existing = ProductIdentifier::where('created_by', $creatorId)->whereIn('identifier_value', $unique)->exists();
                        if ($existing) {
                            return redirect()->back()->with('error', __('One or more Serial/MAC already exists in the system.'));
                        }

                        $type = in_array($product->identifier_type ?? 'serial', ['serial', 'mac', 'both'], true) ? ($product->identifier_type ?? 'serial') : 'serial';
                        $warrantyDays = (int) ($product->warranty_days ?? 0);
                        $warrantyEnd = null;
                        if ($warrantyDays > 0 && ! empty($purchase->purchase_date)) {
                            $warrantyEnd = \Carbon\Carbon::parse($purchase->purchase_date)->addDays($warrantyDays)->toDateString();
                        }

                        foreach ($unique as $val) {
                            ProductIdentifier::create([
                                'product_id' => (int) $purchaseProduct->product_id,
                                'warehouse_id' => $warehouseId,
                                'purchase_id' => (int) $purchase->id,
                                'purchase_product_id' => (int) $purchaseProduct->id,
                                'identifier_type' => $type,
                                'identifier_value' => $val,
                                'status' => 'in_stock',
                                'warranty_end_date' => $warrantyEnd,
                                'created_by' => (int) $creatorId,
                            ]);
                        }
                    }

                    $type = 'purchase';
                    $type_id = $purchase->id;
                    $description = $line['quantity'] . '  ' . __(' quantity add in purchase') . ' ' . \Auth::user()->purchaseNumberFormat($purchase->purchase_id);
                    if (! empty($line['_variant_label'] ?? null)) {
                        $description .= ' | ' . $line['_variant_label'];
                    }
                    if (! empty($purchaseProduct->batch_no)) {
                        $description .= ' | ' . __('Batch') . ': ' . $purchaseProduct->batch_no;
                    }
                    if (! empty($purchaseProduct->expiry_date)) {
                        $description .= ' | ' . __('Expiry') . ': ' . $purchaseProduct->expiry_date;
                    }

                    $selectedOpts = \App\Services\ProductOptionService::normalizeSelectedOptions($purchaseProduct->selected_options_json);
                    if (($product->options_enabled ?? false) && $selectedOpts !== []) {
                        \App\Services\ProductOptionService::addOptionsForPurchase(
                            $selectedOpts,
                            (float) $purchaseProduct->quantity,
                            $warehouseId,
                            $creatorId,
                            $type,
                            $description,
                            (int) $type_id
                        );
                    } else {
                        Utility::total_quantity('plus', $purchaseProduct->quantity, $purchaseProduct->product_id);
                        Utility::addProductStock($line['item'], $line['quantity'], $type, $description, $type_id);

                        if (isset($line['item'])) {
                            Utility::addWarehouseStock($line['item'], $line['quantity'], $warehouseId, $pRow, $pShelf, $variantId);
                        }
                    }
                }
            }

            if ($request->filled('purchase_quotation_id')) {
                PurchaseQuotation::where('id', (int) $request->purchase_quotation_id)
                    ->where('created_by', (int) \Auth::user()->creatorId())
                    ->update([
                        'converted_purchase_id' => $purchase->id,
                        'is_converted' => 1,
                    ]);
            }

            if ($request->filled('purchase_proforma_id')) {
                \App\Models\PurchaseProforma::where('id', (int) $request->purchase_proforma_id)
                    ->where('created_by', (int) \Auth::user()->creatorId())
                    ->update([
                        'converted_purchase_id' => $purchase->id,
                        'is_converted' => 1,
                    ]);
            }

            if ($request->filled('proforma_id')) {
                Proforma::where('id', (int) $request->proforma_id)
                    ->where('created_by', (int) \Auth::user()->creatorId())
                    ->update([
                        'converted_purchase_id' => $purchase->id,
                        'is_purchase_converted' => 1,
                    ]);
            }

            if ($request->filled('quotation_id')) {
                Quotation::where('id', (int) $request->quotation_id)
                    ->where('created_by', (int) \Auth::user()->creatorId())
                    ->update([
                        'converted_purchase_id' => $purchase->id,
                    ]);
            }

            if ($request->filled('proforma_id')) {
                return redirect()->route('purchase.show', Crypt::encrypt($purchase->id))
                    ->with('success', __('Purchase order created from proforma.'));
            }

            if ($request->filled('purchase_proforma_id')) {
                return redirect()->route('purchase.show', Crypt::encrypt($purchase->id))
                    ->with('success', __('Purchase order created from purchase proforma.'));
            }

            if ($request->filled('purchase_quotation_id')) {
                return redirect()->route('purchase.show', Crypt::encrypt($purchase->id))
                    ->with('success', __('Purchase order created from vendor quote.'));
            }

            if ($request->filled('quotation_id')) {
                return redirect()->route('purchase.show', Crypt::encrypt($purchase->id))
                    ->with('success', __('Purchase order created from quotation.'));
            }

            return redirect()->route('purchase.index', $purchase->id)->with('success', __('Purchase successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Purchase order (stock) → Vendor bill (AP / accounting) without duplicating inventory.
     */
    public function convertToVendorBill($encryptedId)
    {
        if (! \Auth::user()->can('create bill')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $pid = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid purchase.'));
        }

        $purchase = Purchase::with('items')->where('created_by', \Auth::user()->creatorId())->find($pid);
        if (! $purchase) {
            return redirect()->back()->with('error', __('Purchase not found.'));
        }
        if (! empty($purchase->converted_bill_id)) {
            return redirect()->route('bill.show', Crypt::encrypt($purchase->converted_bill_id))
                ->with('info', __('Vendor bill already exists for this purchase.'));
        }

        $bill = DB::transaction(function () use ($purchase) {
            $nextBillNo = $this->vendorBillNextNumber();
            $bill = new Bill();
            $bill->bill_id = $nextBillNo;
            $bill->vender_id = $purchase->vender_id;
            $bill->bill_date = $purchase->purchase_date ?? now()->toDateString();
            $bill->due_date = $purchase->purchase_date ?? now()->toDateString();
            $bill->status = 0;
            $bill->type = 'Bill';
            $bill->user_type = 'vendor';
            $bill->category_id = $purchase->category_id ?: 0;
            $bill->order_number = (int) ($purchase->purchase_number ?? 0);
            $bill->purchase_id = $purchase->id;
            $bill->suppress_inventory = true;
            $bill->created_by = \Auth::user()->creatorId();
            $bill->save();

            foreach ($purchase->items as $line) {
                $bp = new BillProduct();
                $bp->bill_id = $bill->id;
                $bp->product_id = $line->product_id;
                $bp->quantity = $line->quantity;
                $bp->tax = $line->tax;
                $bp->discount = $line->discount;
                $bp->price = $line->price;
                $bp->description = $line->description;
                $bp->save();
            }

            $purchase->converted_bill_id = $bill->id;
            $purchase->save();

            return $bill;
        });

        return redirect()->route('bill.show', Crypt::encrypt($bill->id))->with('success', __('Vendor bill created from purchase. Send the bill to post accounting entries.'));
    }

    /**
     * Purchase order → Sales invoice (Quote → Proforma → PO → Invoice).
     */
    public function convertToInvoiceForm($encryptedId)
    {
        if (! \Auth::user()->can('create invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $pid = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid purchase.'));
        }

        $purchase = Purchase::with(['items', 'sourceProforma'])->where('created_by', \Auth::user()->creatorId())->find($pid);
        if (! $purchase) {
            return redirect()->back()->with('error', __('Purchase not found.'));
        }
        if ((int) $purchase->is_invoice_converted === 1 && ! empty($purchase->converted_invoice_id)) {
            return redirect()->route('invoice.show', Crypt::encrypt($purchase->converted_invoice_id))
                ->with('info', __('Sales invoice already exists for this purchase.'));
        }

        $customers = \App\Models\Customer::forDocuments()->get()->pluck('name', 'id');
        $customers->prepend(__('Select Customer'), '');
        $defaultCustomerId = $purchase->sourceProforma?->customer_id;
        $category = ProductServiceCategory::where('created_by', \Auth::user()->creatorId())
            ->where(function ($q) {
                $q->where('type', 'income')->orWhere('type', 1);
            })
            ->orderBy('id')
            ->first();

        $quotationReference = '';
        if (! empty($purchase->quotation_id)) {
            $sourceQuotation = Quotation::find($purchase->quotation_id);
            if ($sourceQuotation) {
                $quotationReference = \Auth::user()->quotationNumberFormat($sourceQuotation->quotation_id);
            }
        } elseif (! empty($purchase->sourceProforma?->quotation_id)) {
            $sourceQuotation = Quotation::find($purchase->sourceProforma->quotation_id);
            if ($sourceQuotation) {
                $quotationReference = \Auth::user()->quotationNumberFormat($sourceQuotation->quotation_id);
            }
        }

        return view('purchase.convert_invoice', compact('purchase', 'customers', 'defaultCustomerId', 'category', 'quotationReference'));
    }

    public function convertToInvoiceStore(Request $request, $encryptedId)
    {
        if (! \Auth::user()->can('create invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $pid = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid purchase.'));
        }

        $purchase = Purchase::with(['items', 'sourceProforma.items'])->where('created_by', \Auth::user()->creatorId())->find($pid);
        if (! $purchase) {
            return redirect()->back()->with('error', __('Purchase not found.'));
        }
        if ((int) $purchase->is_invoice_converted === 1 && ! empty($purchase->converted_invoice_id)) {
            return redirect()->route('invoice.show', Crypt::encrypt($purchase->converted_invoice_id))
                ->with('info', __('Sales invoice already exists for this purchase.'));
        }

        $validator = \Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'category_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first())->withInput();
        }

        $proformaPricesByVariant = [];
        $proformaPricesByProduct = [];
        if ($purchase->sourceProforma) {
            foreach ($purchase->sourceProforma->items as $pfItem) {
                $productKey = (int) $pfItem->product_id;
                $variantKey = $pfItem->product_variant_id ? (int) $pfItem->product_variant_id : 0;
                $proformaPricesByVariant[$productKey . ':' . $variantKey] = (float) $pfItem->price;
                // First matching product_id wins as fallback when variant does not match.
                if (! array_key_exists($productKey, $proformaPricesByProduct)) {
                    $proformaPricesByProduct[$productKey] = (float) $pfItem->price;
                }
            }
        }

        $selected = $request->input('items', []);
        if (! is_array($selected) || $selected === []) {
            return redirect()->back()->with('error', __('Select at least one item.'))->withInput();
        }

        $items = [];
        foreach ($purchase->items as $line) {
            $payload = $selected[$line->id] ?? null;
            if (! is_array($payload) || empty($payload['selected'])) {
                continue;
            }
            $qty = (float) ($payload['quantity'] ?? $line->quantity);
            if ($qty <= 0) {
                return redirect()->back()->with('error', __('Quantity must be greater than zero.'))->withInput();
            }

            $product = ProductService::find($line->product_id);
            $salePrice = $product && (float) ($product->sale_price ?? 0) > 0
                ? (float) $product->sale_price
                : null;
            $productKey = (int) $line->product_id;
            $variantKey = $line->product_variant_id ? (int) $line->product_variant_id : 0;
            $price = $proformaPricesByVariant[$productKey . ':' . $variantKey]
                ?? $proformaPricesByProduct[$productKey]
                ?? $salePrice
                ?? (float) $line->price;
            $discount = (float) $line->discount;
            $originalQty = (float) $line->quantity;
            if ($originalQty > 0 && abs($qty - $originalQty) > 0.00001) {
                $discount = $discount * ($qty / $originalQty);
            }

            $items[] = [
                'item' => $line->product_id,
                'quantity' => $qty,
                'price' => $price,
                'tax' => $line->tax,
                'discount' => $discount,
                'description' => $line->description,
                'product_variant_id' => $line->product_variant_id ?: '',
                'identifiers' => '',
            ];
        }

        if ($items === []) {
            return redirect()->back()->with('error', __('Select at least one item.'))->withInput();
        }

        $quotationReference = '';
        if (! empty($purchase->quotation_id)) {
            $sourceQuotation = Quotation::find($purchase->quotation_id);
            if ($sourceQuotation) {
                $quotationReference = \Auth::user()->quotationNumberFormat($sourceQuotation->quotation_id);
            }
        } elseif (! empty($purchase->proforma_id)) {
            $sourceProforma = Proforma::find($purchase->proforma_id);
            if ($sourceProforma && ! empty($sourceProforma->quotation_id)) {
                $sourceQuotation = Quotation::find($sourceProforma->quotation_id);
                if ($sourceQuotation) {
                    $quotationReference = \Auth::user()->quotationNumberFormat($sourceQuotation->quotation_id);
                }
            }
        }

        $payload = [
            'customer_id' => $request->customer_id,
            'issue_date' => $request->issue_date,
            'due_date' => $request->due_date,
            'category_id' => $request->category_id,
            'ref_number' => $request->ref_number ?: (\Auth::user()->purchaseNumberFormat($purchase->purchase_id)),
            'client_po_number' => $request->input('client_po_number'),
            'client_po_date' => $request->input('client_po_date'),
            'client_po_details' => $request->input('client_po_details'),
            'quotation_reference' => $request->input('quotation_reference') ?: $quotationReference,
            'items' => $items,
            'source_purchase_id' => $purchase->id,
            '_token' => csrf_token(),
        ];
        if (! empty($purchase->proforma_id)) {
            $payload['source_proforma_id'] = $purchase->proforma_id;
        }
        if (! empty($purchase->quotation_id)) {
            $payload['source_quotation_id'] = $purchase->quotation_id;
        } elseif (! empty($purchase->proforma_id)) {
            $sourceProforma = $purchase->sourceProforma ?: Proforma::find($purchase->proforma_id);
            if ($sourceProforma && ! empty($sourceProforma->quotation_id)) {
                $payload['source_quotation_id'] = (int) $sourceProforma->quotation_id;
            }
        }

        $req = Request::create('/invoice', 'POST', $payload);
        $req->setUserResolver(fn () => \Auth::user());

        return app(InvoiceController::class)->store($req);
    }

    protected function vendorBillNextNumber(): int
    {
        $latest = Bill::where('created_by', '=', \Auth::user()->creatorId())->latest('id')->first();
        if (! $latest) {
            return 1;
        }

        return (int) $latest->bill_id + 1;
    }


    public function show($ids)
    {

        if(\Auth::user()->can('show purchase'))
        {
            try {
                $id       = Crypt::decrypt($ids);
            } catch (\Throwable $th) {
                return redirect()->back()->with('error', __('Purchase Not Found.'));
            }

            $id   = Crypt::decrypt($ids);
            $purchase = Purchase::find($id);

            if($purchase->created_by == \Auth::user()->creatorId())
            {

                $purchasePayment = PurchasePayment::where('purchase_id', $purchase->id)->first();
                $vendor      = $purchase->vender;
                $iteams      = $purchase->items;

                return view('purchase.view', compact('purchase', 'vendor', 'iteams', 'purchasePayment'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function edit($idsd)
    {
        if(\Auth::user()->can('edit purchase'))
        {

            try{
                $idwww   = Crypt::decrypt($idsd);
            } catch (\Exception $e){
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
            $purchase     = Purchase::find($idwww);
            $category = ProductServiceCategory::where('created_by', \Auth::user()->creatorId())->where('type', 'expense')->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');
            $warehouse     = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            $purchase_number      = \Auth::user()->purchaseNumberFormat($purchase->purchase_id);
            $venders          = Vender::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $product_services = ProductService::where('created_by', \Auth::user()->creatorId())->where('type','!=', 'service')->get()->pluck('name', 'id');

            return view('purchase.edit', compact('venders', 'product_services', 'purchase', 'warehouse','purchase_number', 'category'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function update(Request $request, Purchase $purchase)
    {


        if(\Auth::user()->can('edit purchase'))
        {

            if($purchase->created_by == \Auth::user()->creatorId())
            {
                $validator = \Validator::make(
                    $request->all(), [
                        'vender_id' => 'required',
                        'purchase_date' => 'required',
                        'items' => 'required',
                    ]
                );
                if($validator->fails())
                {
                    $messages = $validator->getMessageBag();

                    return redirect()->route('purchase.index')->with('error', $messages->first());
                }
                $purchase->vender_id      = $request->vender_id;
                $purchase->purchase_date      = $request->purchase_date;
                $purchase->category_id    = $request->category_id;
                $purchase->save();
                $products = $request->items;
                $creatorId = (int) \Auth::user()->creatorId();

                for($i = 0; $i < count($products); $i++)
                {
                    $existingLine = ! empty($products[$i]['id'])
                        ? PurchaseProduct::where('id', $products[$i]['id'])->where('purchase_id', $purchase->id)->first()
                        : null;

                    if($existingLine == null)
                    {
                        $purchaseProduct             = new PurchaseProduct();
                        $purchaseProduct->purchase_id    = $purchase->id;

                        Utility::total_quantity('plus',$products[$i]['quantity'],$products[$i]['item']);
                        $old_qty = 0;
                        $old_pid = null;
                        $old_vid = null;
                        $prev_row = '';
                        $prev_shelf = '';
                    }
                    else{
                        $purchaseProduct = $existingLine;
                        $old_qty = $purchaseProduct->quantity;
                        $old_pid = (int) $purchaseProduct->product_id;
                        $old_vid = $purchaseProduct->product_variant_id ? (int) $purchaseProduct->product_variant_id : null;
                        [$prev_row, $prev_shelf] = Utility::normalizeWarehouseLocation($purchaseProduct->row_no, $purchaseProduct->shelf_no);
                        Utility::total_quantity('minus',$purchaseProduct->quantity,$purchaseProduct->product_id);
                    }

                    if(isset($products[$i]['item']))
                    {
                        $purchaseProduct->product_id = $products[$i]['item'];
                    }
                    $product = ProductService::where('id', $purchaseProduct->product_id)->where('created_by', $creatorId)->first();
                    $variantId = isset($products[$i]['product_variant_id']) ? (int) $products[$i]['product_variant_id'] : null;
                    if ($variantId !== null && $variantId <= 0) {
                        $variantId = null;
                    }
                    if ($product && ($product->variants_enabled ?? false)) {
                        if (! $variantId) {
                            return redirect()->back()->with('error', __('Select size / color variant for one of the items.'))->withInput();
                        }
                        $ok = ProductVariant::where('id', $variantId)->where('product_service_id', $product->id)->where('created_by', $creatorId)->exists();
                        if (! $ok) {
                            return redirect()->back()->with('error', __('Invalid size / color variant for one of the items.'))->withInput();
                        }
                    } else {
                        $variantId = null;
                    }

                    $purchaseProduct->quantity    = $products[$i]['quantity'];
                    $purchaseProduct->tax         = $products[$i]['tax'];
                    $purchaseProduct->discount    = $products[$i]['discount'];
                    $purchaseProduct->price       = $products[$i]['price'];
                    $purchaseProduct->description = $products[$i]['description'];
                    [$lineRow, $lineShelf] = Utility::normalizeWarehouseLocation($products[$i]['row_no'] ?? '', $products[$i]['shelf_no'] ?? '');
                    $purchaseProduct->row_no = $lineRow !== '' ? $lineRow : null;
                    $purchaseProduct->shelf_no = $lineShelf !== '' ? $lineShelf : null;
                    $purchaseProduct->product_variant_id = $variantId;
                    $purchaseProduct->save();

                    if ($products[$i]['id']>0) {
                        Utility::total_quantity('plus',$products[$i]['quantity'],$purchaseProduct->product_id);

                    }

                    //Product Stock Report
                    $type='purchase';
                    $type_id = $purchase->id;
                    StockReport::where('type','=','purchase')->where('type_id','=',$purchase->id)->delete();
                    $description=$products[$i]['quantity'].'  '.__(' quantity add in purchase').' '. \Auth::user()->purchaseNumberFormat($purchase->purchase_id);

                    if(isset($products[$i]['item']) ){
                        Utility::addProductStock( $products[$i]['item'],$products[$i]['quantity'],$type,$description,$type_id);
                    }

                    if(isset($products[$i]['item']) && ! empty($request->warehouse_id)){
                        $wid = (int) $request->warehouse_id;
                        $new_pid = (int) $purchaseProduct->product_id;
                        $new_qty = (float) $purchaseProduct->quantity;
                        [$new_row, $new_shelf] = Utility::normalizeWarehouseLocation($purchaseProduct->row_no, $purchaseProduct->shelf_no);
                        if ($existingLine != null && $old_pid && $old_qty) {
                            Utility::addWarehouseStock($old_pid, -$old_qty, $wid, $prev_row, $prev_shelf, $old_vid);
                        }
                        Utility::addWarehouseStock($new_pid, $new_qty, $wid, $new_row, $new_shelf, $variantId);
                    }

                }

                return redirect()->route('purchase.index')->with('success', __('Purchase successfully updated.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Purchase  $purchase
     * @return \Illuminate\Http\Response
     */
    public function destroy(Purchase $purchase)
    {
        if(\Auth::user()->can('delete purchase'))
        {
            if($purchase->created_by == \Auth::user()->creatorId())
            {
                $purchase_products = PurchaseProduct::where('purchase_id',$purchase->id)->get();

                $purchasepayments = $purchase->payments;
                foreach($purchasepayments as $key => $value)
                {
                    $purchasepayment = PurchasePayment::find($value->id)->first();
                    $purchasepayment->delete();
                }

                foreach($purchase_products as $purchase_product)
                {
                    [$dRow, $dShelf] = Utility::normalizeWarehouseLocation($purchase_product->row_no, $purchase_product->shelf_no);
                    $dVid = $purchase_product->product_variant_id ? (int) $purchase_product->product_variant_id : null;
                    $warehouse_qty = WarehouseProduct::where('warehouse_id', $purchase->warehouse_id)
                        ->where('product_id', $purchase_product->product_id)
                        ->where('product_variant_id', $dVid)
                        ->where('row_no', $dRow)
                        ->where('shelf_no', $dShelf)
                        ->where('created_by', \Auth::user()->creatorId())
                        ->first();
                    if (empty($warehouse_qty)) {
                        $warehouse_qty = WarehouseProduct::where('warehouse_id', $purchase->warehouse_id)
                            ->where('product_id', $purchase_product->product_id)
                            ->where('product_variant_id', $dVid)
                            ->where('row_no', $dRow)
                            ->where('shelf_no', $dShelf)
                            ->first();
                    }
                    if (empty($warehouse_qty)) {
                        $warehouse_qty = WarehouseProduct::where('warehouse_id', $purchase->warehouse_id)
                            ->where('product_id', $purchase_product->product_id)
                            ->where('product_variant_id', $dVid)
                            ->first();
                    }

                    $warehouse_transfers = WarehouseTransfer::where('product_id',$purchase_product->product_id)->where('from_warehouse',$purchase->warehouse_id)->get();
                    foreach ($warehouse_transfers as $warehouse_transfer)
                    {
                        $temp = WarehouseProduct::where('warehouse_id',$warehouse_transfer->to_warehouse)->first();
                        if($temp)
                        {
                            $temp->quantity = $temp->quantity - $warehouse_transfer->quantity;
                            if($temp->quantity > 0)
                            {
                                $temp->save();
                            }
                            else
                            {
                                $temp->delete();
                            }

                        }
                    }
                    if(!empty($warehouse_qty))
                    {
                        $warehouse_qty->quantity = $warehouse_qty->quantity - $purchase_product->quantity;
                        if( $warehouse_qty->quantity > 0)
                        {
                            $warehouse_qty->save();
                        }
                        else
                        {
                            $warehouse_qty->delete();
                        }
                    }
                    $product_qty = ProductService::where('id',$purchase_product->product_id)->first();
                    if(!empty($product_qty))
                    {
                        $product_qty->quantity = $product_qty->quantity - $purchase_product->quantity;
                        $product_qty->save();
                    }
                    $purchase_product->delete();

                }

                $purchase->delete();
                PurchaseProduct::where('purchase_id', '=', $purchase->id)->delete();


                return redirect()->route('purchase.index')->with('success', __('Purchase successfully deleted.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    function purchaseNumber()
    {
        $latest = Purchase::where('created_by', '=', \Auth::user()->creatorId())->latest()->first();
        if(!$latest)
        {
            return 1;
        }

        return $latest->purchase_id + 1;
    }
    public function sent($id)
    {
        if(\Auth::user()->can('send purchase'))
        {
            $purchase            = Purchase::where('id', $id)->first();
            $purchase->send_date = date('Y-m-d');
            $purchase->status    = 1;
            $purchase->save();

            $vender = Vender::where('id', $purchase->vender_id)->first();

            $purchase->name = !empty($vender) ? $vender->name : '';
            $purchase->purchase = \Auth::user()->purchaseNumberFormat($purchase->purchase_id);

            $purchaseId    = Crypt::encrypt($purchase->id);
            $purchase->url = route('purchase.pdf', $purchaseId);

            Utility::userBalance('vendor', $vender->id, $purchase->getTotal(), 'credit');

            $vendorArr = [
                'vender_bill_name' => $purchase->name,
                'vender_bill_number' =>$purchase->purchase,
                'vender_bill_url' => $purchase->url,

            ];
            $resp = SalesPurchaseNotifier::vendorBillSent($vender, $vendorArr);

            return redirect()->back()->with('success', __('Purchase successfully sent.') . (($resp !== null && ($resp['is_success'] ?? true) == false && !empty($resp['error'])) ? '<br> <span class="text-danger">' . $resp['error'] . '</span>' : ''));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function resent($id)
    {

        if(\Auth::user()->can('send purchase'))
        {
            $purchase = Purchase::where('id', $id)->first();

            $vender = Vender::where('id', $purchase->vender_id)->first();

            $purchase->name = !empty($vender) ? $vender->name : '';
            $purchase->purchase = \Auth::user()->purchaseNumberFormat($purchase->purchase_id);

            $purchaseId    = Crypt::encrypt($purchase->id);
            $purchase->url = route('purchase.pdf', $purchaseId);

        return redirect()->back()->with('success', __('Bill successfully sent.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function purchase($purchase_id)
    {

        $settings = Utility::settings();
        try{
            $purchaseId   = Crypt::decrypt($purchase_id);
        } catch (\Exception $e){
            return redirect()->back()->with('error', __('Something went wrong.'));
        }

        $purchase  = Purchase::where('id', $purchaseId)->first();
        $data  = DB::table('settings');
        $data  = $data->where('created_by', '=', $purchase->created_by);
        $data1 = $data->get();

        foreach($data1 as $row)
        {
            $settings[$row->name] = $row->value;
        }

        $vendor = $purchase->vender;

        $totalTaxPrice = 0;
        $totalQuantity = 0;
        $totalRate     = 0;
        $totalDiscount = 0;
        $taxesData     = [];
        $items         = [];

        foreach($purchase->items as $product)
        {

            $item              = new \stdClass();
            $item->name        = !empty($product->product) ? $product->product->name : '';
            $item->quantity    = $product->quantity;
            $item->tax         = $product->tax;
            $item->discount    = $product->discount;
            $item->price       = $product->price;
            $item->description = $product->description;

            $totalQuantity += $item->quantity;
            $totalRate     += $item->price;
            $totalDiscount += $item->discount;

            $taxes     = Utility::tax($product->tax);
            $itemTaxes = [];
            if(!empty($item->tax))
            {
                foreach($taxes as $tax)
                {
                    $taxPrice      = Utility::taxRate($tax->rate, $item->price, $item->quantity,$item->discount);
                    $totalTaxPrice += $taxPrice;

                    $itemTax['name']  = $tax->name;
                    $itemTax['rate']  = $tax->rate . '%';
                    $itemTax['price'] = Utility::priceFormat($settings, $taxPrice);
                    $itemTax['tax_price'] =$taxPrice;
                    $itemTaxes[]      = $itemTax;


                    if(array_key_exists($tax->name, $taxesData))
                    {
                        $taxesData[$tax->name] = $taxesData[$tax->name] + $taxPrice;
                    }
                    else
                    {
                        $taxesData[$tax->name] = $taxPrice;
                    }

                }

                $item->itemTax = $itemTaxes;
            }
            else
            {
                $item->itemTax = [];
            }
            $items[] = $item;
        }

        $purchase->itemData      = $items;
        $purchase->totalTaxPrice = $totalTaxPrice;
        $purchase->totalQuantity = $totalQuantity;
        $purchase->totalRate     = $totalRate;
        $purchase->totalDiscount = $totalDiscount;
        $purchase->taxesData     = $taxesData;



        $logo         = asset(Storage::url('uploads/logo/'));
        $company_logo = Utility::getValByName('company_logo_dark');
        $purchase_logo = Utility::getValByName('purchase_logo');
        if(isset($purchase_logo) && !empty($purchase_logo))
        {
            $img = Utility::get_file('purchase_logo/') . $purchase_logo;
        }
        else{
            $img          = asset($logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png'));
        }

        if($purchase)
        {
            $color      = '#' . $settings['purchase_color'];
            $font_color = Utility::getFontColor($color);

            $custom = DocumentLayoutRenderer::tryRender('purchase', $purchase, [
                'settings' => $settings,
                'color' => $color,
                'font_color' => $font_color,
                'img' => $img,
                'party' => $vendor,
                'vendor' => $vendor,
            ]);
            if ($custom) {
                return $custom;
            }

            return view('purchase.templates.' . $settings['purchase_template'], compact('purchase', 'color', 'settings', 'vendor', 'img', 'font_color'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function previewPurchase($template, $color)
    {
        $objUser  = \Auth::user();
        $settings = Utility::settings();
        $purchase     = new Purchase();

        $vendor                   = new \stdClass();
        $vendor->email            = '<Email>';
        $vendor->shipping_name    = '<Vendor Name>';
        $vendor->shipping_country = '<Country>';
        $vendor->shipping_state   = '<State>';
        $vendor->shipping_city    = '<City>';
        $vendor->shipping_phone   = '<Vendor Phone Number>';
        $vendor->shipping_zip     = '<Zip>';
        $vendor->shipping_address = '<Address>';
        $vendor->billing_name     = '<Vendor Name>';
        $vendor->billing_country  = '<Country>';
        $vendor->billing_state    = '<State>';
        $vendor->billing_city     = '<City>';
        $vendor->billing_phone    = '<Vendor Phone Number>';
        $vendor->billing_zip      = '<Zip>';
        $vendor->billing_address  = '<Address>';

        $totalTaxPrice = 0;
        $taxesData     = [];
        $items         = [];
        for($i = 1; $i <= 3; $i++)
        {
            $item           = new \stdClass();
            $item->name     = 'Item ' . $i;
            $item->quantity = 1;
            $item->tax      = 5;
            $item->discount = 50;
            $item->price    = 100;

            $taxes = [
                'Tax 1',
                'Tax 2',
            ];

            $itemTaxes = [];
            foreach($taxes as $k => $tax)
            {
                $taxPrice         = 10;
                $totalTaxPrice    += $taxPrice;
                $itemTax['name']  = 'Tax ' . $k;
                $itemTax['rate']  = '10 %';
                $itemTax['price'] = '$10';
                $itemTax['tax_price'] = 10;
                $itemTaxes[]      = $itemTax;
                if(array_key_exists('Tax ' . $k, $taxesData))
                {
                    $taxesData['Tax ' . $k] = $taxesData['Tax 1'] + $taxPrice;
                }
                else
                {
                    $taxesData['Tax ' . $k] = $taxPrice;
                }
            }
            $item->itemTax = $itemTaxes;
            $items[]       = $item;
        }

        $purchase->purchase_id    = 1;
        $purchase->issue_date = date('Y-m-d H:i:s');
        $purchase->itemData   = $items;

        $purchase->totalTaxPrice = 60;
        $purchase->totalQuantity = 3;
        $purchase->totalRate     = 300;
        $purchase->totalDiscount = 10;
        $purchase->taxesData     = $taxesData;
        $purchase->created_by     = $objUser->creatorId();

        $preview      = 1;
        $color        = '#' . $color;
        $font_color   = Utility::getFontColor($color);

        $logo         = asset(Storage::url('uploads/logo/'));
        $company_logo = Utility::getValByName('company_logo_dark');
        $settings_data = \App\Models\Utility::settingsById($purchase->created_by);
        $purchase_logo = $settings_data['purchase_logo'];

        if(isset($purchase_logo) && !empty($purchase_logo))
        {
            $img = Utility::get_file('purchase_logo/') . $purchase_logo;
        }
        else{
            $img          = asset($logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png'));
        }


        return view('purchase.templates.' . $template, compact('purchase', 'preview', 'color', 'img', 'settings', 'vendor', 'font_color'));
    }

    public function savePurchaseTemplateSettings(Request $request)
    {

        $post = $request->all();
        unset($post['_token']);

        if(isset($post['purchase_template']) && (!isset($post['purchase_color']) || empty($post['purchase_color'])))
        {
            $post['purchase_color'] = "ffffff";
        }


        if($request->purchase_logo)
        {
            $dir = 'purchase_logo/';
            $purchase_logo = \Auth::user()->id . '_purchase_logo.png';
            $validation =[
                'mimes:'.'png',
                'max:'.'20480',
            ];
            $path = Utility::upload_file($request,'purchase_logo',$purchase_logo,$dir,$validation);
            if($path['flag']==0)
            {
                return redirect()->back()->with('error', __($path['msg']));
            }
            $post['purchase_logo'] = $purchase_logo;
        }


        foreach($post as $key => $data)
        {
            \DB::insert(
                'insert into settings (`value`, `name`,`created_by`) values (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ', [
                    $data,
                    $key,
                    \Auth::user()->creatorId(),
                ]
            );
        }

        return redirect()->back()->with('success', __('Purchase Setting updated successfully'));
    }

    public function items(Request $request)
    {

        $items = PurchaseProduct::where('purchase_id', $request->purchase_id)->where('product_id', $request->product_id)->first();

        return json_encode($items);
    }

    public function purchaseLink($purchaseId)
    {
        try {
            $id       = Crypt::decrypt($purchaseId);
            $purchase = Purchase::findOrFail($id);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Purchase Not Found.'));
        }

        if(!empty($purchase))
        {
            $user_id        = $purchase->created_by;
            $user           = User::find($user_id);
            $purchasePayment = PurchasePayment::where('purchase_id', $purchase->id)->first();
            $vendor = $purchase->vender;
            $iteams   = $purchase->items;

            return view('purchase.customer_bill', compact('purchase', 'vendor', 'iteams','purchasePayment','user'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    public function payment($purchase_id)
    {
        if(\Auth::user()->can('create payment purchase'))
        {
            $purchase    = Purchase::where('id', $purchase_id)->first();
            $venders = Vender::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            $categories = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $accounts   = BankAccount::select('*', \DB::raw("CONCAT(bank_name,' ',holder_name) AS name"))->where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            return view('purchase.payment', compact('venders', 'categories', 'accounts', 'purchase'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));

        }
    }

    public function createPayment(Request $request, $purchase_id)
    {
        if(\Auth::user()->can('create payment purchase'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    'date' => 'required',
                    'amount' => 'required',
                    'account_id' => 'required',

                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $purchasePayment                 = new PurchasePayment();
            $purchasePayment->purchase_id        = $purchase_id;
            $purchasePayment->date           = $request->date;
            $purchasePayment->amount         = $request->amount;
            $purchasePayment->account_id     = $request->account_id;
            $purchasePayment->payment_method = (int) ($request->input('payment_method', 0));
            $purchasePayment->reference      = $request->reference;
            $purchasePayment->description    = $request->description;
            if(!empty($request->add_receipt))
            {
                $fileName = time() . "_" . $request->add_receipt->getClientOriginalName();
                $request->add_receipt->storeAs('uploads/payment', $fileName);
                $purchasePayment->add_receipt = $fileName;
            }
            $purchasePayment->save();

            $purchase  = Purchase::where('id', $purchase_id)->first();
            $due   = $purchase->getDue();
            $total = $purchase->getTotal();

            if($purchase->status == 0)
            {
                $purchase->send_date = date('Y-m-d');
                $purchase->save();
            }

            $paid = $purchase->getPaid();
            if ($paid <= 0) {
                $purchase->status = 2; // Unpaid (e.g. only credit payments)
                $purchase->save();
            } elseif ($due <= 0) {
                $purchase->status = 4; // Paid
                $purchase->save();
            } else {
                $purchase->status = 3; // Partially Paid
                $purchase->save();
            }
            $purchasePayment->user_id    = $purchase->vender_id;
            $purchasePayment->user_type  = 'Vender';
            $purchasePayment->type       = 'Partial';
            $purchasePayment->created_by = \Auth::user()->id;
            $purchasePayment->payment_id = $purchasePayment->id;
            $purchasePayment->category   = 'Bill';
            $purchasePayment->account    = $request->account_id;
            Transaction::addTransaction($purchasePayment);

            $vender = Vender::where('id', $purchase->vender_id)->first();

            $payment         = new PurchasePayment();
            $payment->name   = $vender['name'];
            $payment->method = '-';
            $payment->date   = \Auth::user()->dateFormat($request->date);
            $payment->amount = \Auth::user()->priceFormat($request->amount);
            $payment->bill   = 'bill ' . \Auth::user()->purchaseNumberFormat($purchasePayment->purchase_id);

            Utility::userBalance('vendor', $purchase->vender_id, $request->amount, 'debit');

            Utility::bankAccountBalance($request->account_id, $request->amount, 'debit');

            $billPaymentArr = [
                'vender_name' => $vender->name,
                'vender_email' => $vender->email,
                'payment_name' => $payment->name,
                'payment_amount' => $payment->amount,
                'payment_bill' => $payment->bill,
                'payment_date' => $payment->date,
                'payment_method' => $payment->method,
                'company_name' => $payment->method,
            ];
            $resp = SalesPurchaseNotifier::vendorBillPayment($vender, $billPaymentArr);

            return redirect()->back()->with('success', __('Payment successfully added.') . (($resp !== null && ($resp['is_success'] ?? true) == false && !empty($resp['error'])) ? '<br> <span class="text-danger">' . $resp['error'] . '</span>' : ''));
        }

    }

    public function paymentDestroy(Request $request, $purchase_id, $payment_id)
    {

        if(\Auth::user()->can('delete payment purchase'))
        {
            $payment = PurchasePayment::find($payment_id);
            PurchasePayment::where('id', '=', $payment_id)->delete();

            $purchase = Purchase::where('id', $purchase_id)->first();

            $due   = $purchase->getDue();
            $paid  = $purchase->getPaid();

            if ($due <= 0) {
                $purchase->status = 4; // Paid
            } elseif ($paid > 0) {
                $purchase->status = 3; // Partially Paid
            } else {
                $purchase->status = 2; // Unpaid
            }

            Utility::userBalance('vendor', $purchase->vender_id, $payment->amount, 'credit');
            Utility::bankAccountBalance($payment->account_id, $payment->amount, 'credit');

            $purchase->save();
            $type = 'Partial';
            $user = 'Vender';
            Transaction::destroyTransaction($payment_id, $type, $user);

            return redirect()->back()->with('success', __('Payment successfully deleted.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function vender(Request $request)
    {
        $vender = Vender::where('id', '=', $request->id)->first();

        return view('purchase.vender_detail', compact('vender'));
    }
    public function product(Request $request)
    {
        $data['product']     = $product = ProductService::find($request->product_id);
        $data['unit']        = !empty($product->unit) ? $product->unit->name : '';
        $data['taxRate']     = $taxRate = !empty($product->tax_id) ? $product->taxRate($product->tax_id) : 0;
        $data['taxes']       = !empty($product->tax_id) ? $product->tax($product->tax_id) : 0;
        // Documents always store exclusive unit price and add VAT on top (same as POS / Sales)
        $unitPrice           = $product->exclusivePurchaseUnitPrice();
        $product->purchase_price = $unitPrice;
        $quantity            = 1;
        $taxPrice            = ($taxRate / 100) * ($unitPrice * $quantity);
        $data['totalAmount'] = ($unitPrice * $quantity);
        $data['sale_price_type'] = $product->sale_price_type ?? 'exclusive';
        $data['identifier_tracking'] = (bool) ($product->identifier_tracking ?? false);
        $data['identifier_type'] = $product->identifier_type ?? 'serial';
        $data['variants_enabled'] = (bool) ($product->variants_enabled ?? false);
        $data['variants'] = [];
        if ($data['variants_enabled']) {
            $payload = $product->variantsForDocumentJson('purchase_price');
            $data['variants'] = $payload['variants'];
        }
        $ut = $product->unit_type ?? 'unit';
        $data['unit_type'] = $ut;
        $data['unit_type_label'] = match ($ut) {
            'kg' => __('Kilogram (kg)'),
            'cbm' => __('Cubic meter (CBM)'),
            'sm' => __('Square meter (SQM)'),
            'volume' => __('Volumetric weight'),
            default => __('Piece / unit'),
        };

        $data = array_merge($data, \App\Services\ProductOptionService::productJsonExtras($product));

        return json_encode($data);
    }

    public function productDestroy(Request $request)
    {

        if(\Auth::user()->can('delete purchase'))
        {

            $res = PurchaseProduct::where('id', '=', $request->id)->first();

            $purchase = $res ? Purchase::where('id', (int) $res->purchase_id)->where('created_by', \Auth::user()->creatorId())->first() : null;
            $warehouse_id= $purchase ? (int) $purchase->warehouse_id : 0;
            if (! $res || ! $purchase || $warehouse_id <= 0) {
                return response()->json(['status' => false, 'message' => __('Purchase not found.')]);
            }

            $ware_pro = WarehouseProduct::where('warehouse_id', $warehouse_id)
                ->where('product_id', $res->product_id)
                ->where('product_variant_id', $res->product_variant_id)
                ->first();

            if (! $ware_pro) {
                PurchaseProduct::where('id', '=', $request->id)->delete();
                return response()->json(['status' => true, 'message' => __('Purchase product successfully deleted.')]);
            }

            $qty = $ware_pro->quantity;

            if($res->quantity == $qty || $res->quantity > $qty)
            {
                $ware_pro->delete();
            }
            elseif($res->quantity < $qty)
            {
                $ware_pro->quantity =  $qty - $res->quantity;
                $ware_pro->save();

            }
            PurchaseProduct::where('id', '=', $request->id)->delete();


            return response()->json(['status' => true, 'message' => __('Purchase product successfully deleted.')]);

        }
        else
        {
            return response()->json(['status' => false, 'message' => __('Permission denied.')]);
        }
    }





}
