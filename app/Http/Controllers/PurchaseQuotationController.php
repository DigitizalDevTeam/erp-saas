<?php

namespace App\Http\Controllers;

use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\PurchaseQuotation;
use App\Models\PurchaseQuotationProduct;
use App\Models\Utility;
use App\Models\Vender;
use App\Models\WarehouseProduct;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class PurchaseQuotationController extends Controller
{
    public function index()
    {
        if (! Auth::user()->can('manage purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $quotes = PurchaseQuotation::where('created_by', Auth::user()->creatorId())
            ->with(['vender', 'warehouse'])
            ->orderByDesc('id')
            ->get();

        return view('vendor_quote.index', compact('quotes'));
    }

    public function create()
    {
        if (! Auth::user()->can('create purchase')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $venders = Vender::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $venders->prepend('Select Vendor', '');
        $warehouse = warehouse::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $warehouse->prepend('Select Warehouse', '');
        $quote_number = Auth::user()->vendorQuoteNumberFormat($this->nextQuoteNumber());

        return view('vendor_quote.create', compact('venders', 'warehouse', 'quote_number'));
    }

    public function quoteCreate(Request $request, $cid = 0)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->route('vendor-quote.index')->with('error', __('Permission denied.'));
        }

        $vender = $request->filled('vender_id') ? Vender::find($request->vender_id) : null;
        $wh = $request->filled('warehouse_id') ? warehouse::find($request->warehouse_id) : null;
        $quote_date = $request->quote_date;
        $quote_number = $request->quote_number ?? Auth::user()->vendorQuoteNumberFormat($this->nextQuoteNumber());

        if (! $vender || ! $wh) {
            return redirect()->route('vendor-quote.index')->with('error', __('Please select vendor and warehouse.'));
        }

        $warehouseProducts = WarehouseProduct::where('created_by', '=', Auth::user()->creatorId())
            ->where('warehouse_id', $wh->id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', Auth::user()->creatorId())
            ->whereIn('id', $warehouseProducts)->where('type', '!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');

        $customer = $vender;
        $warehouse = $wh;
        $quotation_date = $quote_date;
        $quotation_number = $quote_number;

        return view('vendor_quote.quotation_create', compact('customer', 'warehouse', 'quotation_date', 'quotation_number', 'product_services'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'vender_id' => 'required',
            'warehouse_id' => 'required',
            'quote_date' => 'nullable|date',
            'quotation_date' => 'nullable|date',
            'items' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $vender = Vender::find($request->vender_id) ?? Vender::where('name', $request->vender_id)->where('created_by', Auth::user()->creatorId())->first();
        $warehouse = warehouse::find($request->warehouse_id) ?? warehouse::where('name', $request->warehouse_id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $vender || ! $warehouse) {
            return redirect()->back()->with('error', __('Invalid vendor or warehouse.'));
        }

        $pq = new PurchaseQuotation();
        $pq->purchase_quotation_id = $this->nextQuoteNumber();
        $pq->vender_id = $vender->id;
        $pq->warehouse_id = $warehouse->id;
        $pq->quote_date = $request->quote_date ?? $request->quotation_date ?? now()->toDateString();
        $pq->status = 0;
        $pq->category_id = 0;
        $pq->created_by = Auth::user()->creatorId();
        $pq->save();

        foreach ($request->items as $row) {
            $line = new PurchaseQuotationProduct();
            $line->purchase_quotation_id = $pq->id;
            $line->product_id = $row['item'];
            $line->price = $row['price'];
            $line->quantity = $row['quantity'];
            $line->tax = $row['tax'];
            $line->discount = $row['discount'];
            $line->description = $row['description'] ?? null;
            $line->save();
        }

        return redirect()->route('vendor-quote.index')->with('success', __('Vendor quote created.'));
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

        $quote = PurchaseQuotation::find($id);
        if (! $quote || (int) $quote->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $vendor = $quote->vender;
        $iteams = $quote->items;

        return view('vendor_quote.view', compact('quote', 'vendor', 'iteams'));
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

        $quote = PurchaseQuotation::find($id);
        if (! $quote || (int) $quote->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $quote->is_converted === 1) {
            return redirect()->back()->with('error', __('This quote was already converted to a purchase order.'));
        }

        $vender = Vender::where('id', $quote->vender_id)->first();
        $warehouse = warehouse::where('id', $quote->warehouse_id)->first();
        $warehouseProducts = WarehouseProduct::where('created_by', '=', Auth::user()->creatorId())
            ->where('warehouse_id', $quote->warehouse_id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', Auth::user()->creatorId())
            ->whereIn('id', $warehouseProducts)->where('type', '!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');
        $quotation_number = Auth::user()->vendorQuoteNumberFormat($quote->purchase_quotation_id);
        $customer = $vender;

        return view('vendor_quote.edit', compact('quote', 'customer', 'product_services', 'warehouse', 'quotation_number'));
    }

    public function update(Request $request, PurchaseQuotation $vendor_quote)
    {
        if (! Auth::user()->can('edit purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $vendor_quote->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $vendor_quote->is_converted === 1) {
            return redirect()->back()->with('error', __('Cannot edit a quote that was converted to a purchase order.'));
        }

        $validator = \Validator::make($request->all(), [
            'vender_id' => 'required',
            'quotation_date' => 'nullable|date',
            'quote_date' => 'nullable|date',
            'items' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->route('vendor-quote.index')->with('error', $validator->getMessageBag()->first());
        }

        $vender = Vender::find($request->vender_id) ?? Vender::where('name', $request->vender_id)->where('created_by', Auth::user()->creatorId())->first();
        $warehouse = warehouse::find($request->warehouse_id) ?? warehouse::where('name', $request->warehouse_id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $vender || ! $warehouse) {
            return redirect()->back()->with('error', __('Invalid vendor or warehouse.'));
        }

        $vendor_quote->vender_id = $vender->id;
        $vendor_quote->warehouse_id = $warehouse->id;
        $vendor_quote->quote_date = $request->quote_date ?? $request->quotation_date ?? $vendor_quote->quote_date;
        $vendor_quote->save();

        foreach ($request->items as $row) {
            $line = PurchaseQuotationProduct::find($row['id'] ?? null);
            if ($line === null) {
                $line = new PurchaseQuotationProduct();
                $line->purchase_quotation_id = $vendor_quote->id;
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

        return redirect()->route('vendor-quote.index')->with('success', __('Vendor quote updated.'));
    }

    public function destroy(PurchaseQuotation $vendor_quote)
    {
        if (! Auth::user()->can('delete purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $vendor_quote->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $vendor_quote->is_converted === 1) {
            return redirect()->back()->with('error', __('Cannot delete a quote linked to a purchase order.'));
        }

        PurchaseQuotationProduct::where('purchase_quotation_id', $vendor_quote->id)->delete();
        $vendor_quote->delete();

        return redirect()->route('vendor-quote.index')->with('success', __('Vendor quote deleted.'));
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
        $taxPrice = ($taxRate / 100) * ($salePrice * $quantity);
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
        PurchaseQuotationProduct::where('id', '=', $request->id)->delete();

        return redirect()->back()->with('success', __('Line removed.'));
    }

    public function items(Request $request)
    {
        $pqId = $request->purchase_quotation_id ?? $request->quotation_id;
        $items = PurchaseQuotationProduct::where('purchase_quotation_id', $pqId)
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
            return redirect()->back()->with('error', __('Invalid quote.'));
        }

        $pq = PurchaseQuotation::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $pq) {
            return redirect()->back()->with('error', __('Vendor quote not found.'));
        }
        if ((int) $pq->is_converted === 1) {
            return redirect()->route('purchase.show', Crypt::encrypt($pq->converted_purchase_id))
                ->with('info', __('Purchase order already exists.'));
        }

        $items = [];
        foreach ($pq->items as $row) {
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
            'vender_id' => $pq->vender_id,
            'warehouse_id' => $pq->warehouse_id,
            'purchase_date' => $pq->quote_date ?? now()->toDateString(),
            'category_id' => $categoryId ?: $pq->category_id,
            'purchase_number' => 0,
            'items' => $items,
            'purchase_quotation_id' => $pq->id,
            '_token' => csrf_token(),
        ]);
        $req->setUserResolver(fn () => Auth::user());

        return app(PurchaseController::class)->store($req);
    }

    protected function nextQuoteNumber(): int
    {
        $latest = PurchaseQuotation::where('created_by', '=', Auth::user()->creatorId())->latest('id')->first();
        if (! $latest) {
            return 1;
        }

        return (int) $latest->purchase_quotation_id + 1;
    }
}
