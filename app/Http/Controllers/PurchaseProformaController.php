<?php

namespace App\Http\Controllers;

use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\PurchaseProforma;
use App\Models\PurchaseProformaProduct;
use App\Models\PurchaseQuotation;
use App\Models\Vender;
use App\Models\WarehouseProduct;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PurchaseProformaController extends Controller
{
    public function index()
    {
        if (! Auth::user()->can('manage purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $proformas = PurchaseProforma::where('created_by', Auth::user()->creatorId())
            ->with(['vender', 'warehouse'])
            ->orderByDesc('id')
            ->get();

        return view('purchase_proforma.index', compact('proformas'));
    }

    public function create()
    {
        if (! Auth::user()->can('create purchase')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $venders = Vender::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $venders->prepend(__('Select Supplier'), '');
        $warehouse = warehouse::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $warehouse->prepend(__('Select Warehouse'), '');
        $proforma_number = Auth::user()->purchaseProformaNumberFormat($this->nextProformaNumber());

        return view('purchase_proforma.create', compact('venders', 'warehouse', 'proforma_number'));
    }

    public function proformaCreate(Request $request, $cid = 0)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->route('purchase-proforma.index')->with('error', __('Permission denied.'));
        }

        $vender = $request->filled('vender_id') ? Vender::find($request->vender_id) : null;
        $wh = $request->filled('warehouse_id') ? warehouse::find($request->warehouse_id) : null;
        $proforma_date = $request->proforma_date;
        $proforma_number = $request->proforma_number ?? Auth::user()->purchaseProformaNumberFormat($this->nextProformaNumber());

        if (! $vender || ! $wh) {
            return redirect()->route('purchase-proforma.index')->with('error', __('Please select supplier and warehouse.'));
        }

        $warehouseProducts = WarehouseProduct::where('created_by', '=', Auth::user()->creatorId())
            ->where('warehouse_id', $wh->id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', Auth::user()->creatorId())
            ->whereIn('id', $warehouseProducts)->where('type', '!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');

        $warehouse = $wh;
        $quotation_date = $proforma_date;
        $quotation_number = $proforma_number;

        return view('purchase_proforma.proforma_create', compact(
            'vender',
            'warehouse',
            'quotation_date',
            'quotation_number',
            'proforma_date',
            'proforma_number',
            'product_services'
        ));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'vender_id' => 'required',
            'warehouse_id' => 'required',
            'proforma_date' => 'nullable|date',
            'quotation_date' => 'nullable|date',
            'items' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $vender = Vender::find($request->vender_id) ?? Vender::where('name', $request->vender_id)->where('created_by', Auth::user()->creatorId())->first();
        $warehouse = warehouse::find($request->warehouse_id) ?? warehouse::where('name', $request->warehouse_id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $vender || ! $warehouse) {
            return redirect()->back()->with('error', __('Invalid supplier or warehouse.'));
        }

        $pp = new PurchaseProforma();
        $pp->purchase_proforma_id = $this->nextProformaNumber();
        $pp->vender_id = $vender->id;
        $pp->warehouse_id = $warehouse->id;
        $pp->proforma_date = $request->proforma_date ?? $request->quotation_date ?? now()->toDateString();
        $pp->status = 0;
        $pp->category_id = 0;
        $pp->created_by = Auth::user()->creatorId();
        if ($request->filled('purchase_quotation_id')) {
            $pp->purchase_quotation_id = (int) $request->purchase_quotation_id;
        }
        $pp->save();

        foreach ($request->items as $row) {
            $line = new PurchaseProformaProduct();
            $line->purchase_proforma_id = $pp->id;
            $line->product_id = $row['item'];
            $line->price = $row['price'];
            $line->quantity = $row['quantity'];
            $line->tax = $row['tax'];
            $line->discount = $row['discount'];
            $line->description = $row['description'] ?? null;
            $line->save();
        }

        if ($request->filled('purchase_quotation_id')) {
            PurchaseQuotation::where('id', (int) $request->purchase_quotation_id)
                ->where('created_by', (int) Auth::user()->creatorId())
                ->update(['converted_purchase_proforma_id' => $pp->id]);
        }

        return redirect()->route('purchase-proforma.index')->with('success', __('Purchase proforma created.'));
    }

    public function show($ids)
    {
        if (! Auth::user()->can('show purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($ids);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Not found.'));
        }

        $proforma = PurchaseProforma::find($id);
        if (! $proforma || (int) $proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $vendor = $proforma->vender;
        $iteams = $proforma->items;

        return view('purchase_proforma.view', compact('proforma', 'vendor', 'iteams'));
    }

    public function edit($ids)
    {
        if (! Auth::user()->can('edit purchase')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        try {
            $id = Crypt::decrypt($ids);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something went wrong.'));
        }

        $proforma = PurchaseProforma::find($id);
        if (! $proforma || (int) $proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $proforma->is_converted === 1) {
            return redirect()->back()->with('error', __('This proforma was already converted to a purchase order.'));
        }

        $vender = Vender::where('id', $proforma->vender_id)->first();
        $warehouse = warehouse::where('id', $proforma->warehouse_id)->first();
        $warehouseProducts = WarehouseProduct::where('created_by', '=', Auth::user()->creatorId())
            ->where('warehouse_id', $proforma->warehouse_id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', Auth::user()->creatorId())
            ->whereIn('id', $warehouseProducts)->where('type', '!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');
        $proforma_number = Auth::user()->purchaseProformaNumberFormat($proforma->purchase_proforma_id);
        $quotation_number = $proforma_number;

        return view('purchase_proforma.edit', compact('proforma', 'vender', 'product_services', 'warehouse', 'proforma_number', 'quotation_number'));
    }

    public function update(Request $request, PurchaseProforma $purchase_proforma)
    {
        if (! Auth::user()->can('edit purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $purchase_proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $purchase_proforma->is_converted === 1) {
            return redirect()->back()->with('error', __('Cannot edit a proforma that was converted to a purchase order.'));
        }

        $validator = \Validator::make($request->all(), [
            'vender_id' => 'required',
            'proforma_date' => 'nullable|date',
            'quotation_date' => 'nullable|date',
            'items' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->route('purchase-proforma.index')->with('error', $validator->getMessageBag()->first());
        }

        $vender = Vender::find($request->vender_id) ?? Vender::where('name', $request->vender_id)->where('created_by', Auth::user()->creatorId())->first();
        $warehouse = warehouse::find($request->warehouse_id) ?? warehouse::where('name', $request->warehouse_id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $vender || ! $warehouse) {
            return redirect()->back()->with('error', __('Invalid supplier or warehouse.'));
        }

        $purchase_proforma->vender_id = $vender->id;
        $purchase_proforma->warehouse_id = $warehouse->id;
        $purchase_proforma->proforma_date = $request->proforma_date ?? $request->quotation_date ?? $purchase_proforma->proforma_date;
        $purchase_proforma->save();

        foreach ($request->items as $row) {
            $line = PurchaseProformaProduct::find($row['id'] ?? null);
            if ($line === null) {
                $line = new PurchaseProformaProduct();
                $line->purchase_proforma_id = $purchase_proforma->id;
            }
            if (isset($row['item'])) {
                $line->product_id = $row['item'];
            }
            $line->quantity = $row['quantity'];
            $line->tax = $row['tax'];
            $line->discount = $row['discount'];
            $line->price = $row['price'];
            $line->description = $row['description'] ?? null;
            $line->save();
        }

        return redirect()->route('purchase-proforma.index')->with('success', __('Purchase proforma updated.'));
    }

    public function destroy(PurchaseProforma $purchase_proforma)
    {
        if (! Auth::user()->can('delete purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $purchase_proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $purchase_proforma->is_converted === 1) {
            return redirect()->back()->with('error', __('Cannot delete a proforma linked to a purchase order.'));
        }

        if (! empty($purchase_proforma->purchase_quotation_id)) {
            PurchaseQuotation::where('id', $purchase_proforma->purchase_quotation_id)
                ->where('converted_purchase_proforma_id', $purchase_proforma->id)
                ->update(['converted_purchase_proforma_id' => null]);
        }

        PurchaseProformaProduct::where('purchase_proforma_id', $purchase_proforma->id)->delete();
        $purchase_proforma->delete();

        return redirect()->route('purchase-proforma.index')->with('success', __('Purchase proforma deleted.'));
    }

    public function product(Request $request)
    {
        $data['product'] = $product = ProductService::find($request->product_id);
        if (! $product) {
            return json_encode([]);
        }
        $data['unit'] = ! empty($product->unit) ? $product->unit->name : '';
        $data['taxRate'] = $taxRate = ! empty($product->tax_id) ? $product->taxRate($product->tax_id) : 0;
        $data['taxes'] = ! empty($product->tax_id) ? $product->tax($product->tax_id) : 0;
        $salePrice = $product->purchase_price;
        $quantity = 1;
        $data['totalAmount'] = ($salePrice * $quantity);
        $data['productquantity'] = $product->getQuantity();
        $data['product'] = [
            'sale_price' => $product->purchase_price,
            'description' => $product->description ?? '',
        ];

        return json_encode($data);
    }

    public function productDestroy(Request $request)
    {
        if (! Auth::user()->can('delete purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        PurchaseProformaProduct::where('id', '=', $request->id)->delete();

        return redirect()->back()->with('success', __('Line removed.'));
    }

    public function items(Request $request)
    {
        $ppId = $request->purchase_proforma_id ?? $request->quotation_id;
        $items = PurchaseProformaProduct::where('purchase_proforma_id', $ppId)
            ->where('product_id', $request->product_id)->first();

        return json_encode($items);
    }

    public function convertToPurchase($encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid proforma.'));
        }

        $pp = PurchaseProforma::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $pp) {
            return redirect()->back()->with('error', __('Purchase proforma not found.'));
        }
        if (! empty($pp->converted_purchase_id)) {
            return redirect()->route('purchase.show', Crypt::encrypt($pp->converted_purchase_id))
                ->with('info', __('Purchase order already exists.'));
        }

        $items = [];
        foreach ($pp->items as $row) {
            $items[] = [
                'item' => $row->product_id,
                'quantity' => $row->quantity,
                'price' => $row->price,
                'tax' => $row->tax,
                'discount' => $row->discount,
                'description' => $row->description,
                'product_variant_id' => '',
                'allow_default_variant' => 1,
                'row_no' => '',
                'shelf_no' => '',
                'expiry_date' => '',
                'batch_no' => '',
                'mfg_date' => '',
                'identifiers' => '',
            ];
        }

        $category = ProductServiceCategory::where('created_by', Auth::user()->creatorId())->where('type', 'expense')->orderBy('id')->first();
        $categoryId = $category ? $category->id : 0;

        $req = Request::create('/purchase', 'POST', [
            'vender_id' => $pp->vender_id,
            'warehouse_id' => $pp->warehouse_id,
            'purchase_date' => $pp->proforma_date ?? now()->toDateString(),
            'category_id' => $categoryId ?: $pp->category_id,
            'purchase_number' => 0,
            'items' => $items,
            'purchase_proforma_id' => $pp->id,
            '_token' => csrf_token(),
        ]);
        $req->setUserResolver(fn () => Auth::user());

        return app(PurchaseController::class)->store($req);
    }

    /**
     * Vendor Quote → Purchase Proforma (keeps supplier).
     */
    public function createFromVendorQuote($encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid quote.'));
        }

        $quote = PurchaseQuotation::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $quote) {
            return redirect()->back()->with('error', __('Vendor quote not found.'));
        }
        if (! empty($quote->converted_purchase_proforma_id)) {
            return redirect()->route('purchase-proforma.show', Crypt::encrypt($quote->converted_purchase_proforma_id))
                ->with('info', __('Purchase proforma already exists for this vendor quote.'));
        }

        $proforma = DB::transaction(function () use ($quote) {
            $pp = new PurchaseProforma();
            $pp->purchase_proforma_id = $this->nextProformaNumber();
            $pp->vender_id = $quote->vender_id;
            $pp->warehouse_id = $quote->warehouse_id;
            $pp->proforma_date = $quote->quote_date ?? now()->toDateString();
            $pp->status = 0;
            $pp->category_id = $quote->category_id ?? 0;
            $pp->purchase_quotation_id = $quote->id;
            $pp->created_by = Auth::user()->creatorId();
            $pp->save();

            foreach ($quote->items as $row) {
                $line = new PurchaseProformaProduct();
                $line->purchase_proforma_id = $pp->id;
                $line->product_id = $row->product_id;
                $line->price = $row->price;
                $line->quantity = $row->quantity;
                $line->tax = $row->tax;
                $line->discount = $row->discount;
                $line->description = $row->description;
                $line->save();
            }

            $quote->converted_purchase_proforma_id = $pp->id;
            $quote->save();

            return $pp;
        });

        return redirect()->route('purchase-proforma.show', Crypt::encrypt($proforma->id))
            ->with('success', __('Purchase proforma created from vendor quote.'));
    }

    protected function nextProformaNumber(): int
    {
        $latest = PurchaseProforma::where('created_by', '=', Auth::user()->creatorId())->latest('id')->first();
        if (! $latest) {
            return 1;
        }

        return (int) $latest->purchase_proforma_id + 1;
    }
}
