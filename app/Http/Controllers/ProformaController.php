<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Proforma;
use App\Models\ProformaProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Vender;
use App\Models\Quotation;
use App\Models\QuotationProduct;
use App\Models\Utility;
use App\Models\WarehouseProduct;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ProformaController extends Controller
{
    public function index()
    {
        if (! Auth::user()->can('manage quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $proformas = Proforma::where('created_by', Auth::user()->creatorId())
            ->with(['customer', 'warehouse'])
            ->orderByDesc('id')
            ->get();

        return view('proforma.index', compact('proformas'));
    }

    public function create()
    {
        if (! Auth::user()->can('create quotation')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $customers = Customer::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $customers->prepend('Select Customer', '');
        $warehouse = warehouse::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $warehouse->prepend('Select Warehouse', '');
        $proforma_number = Auth::user()->proformaNumberFormat($this->nextProformaNumber());

        return view('proforma.create', compact('customers', 'warehouse', 'proforma_number'));
    }

    public function proformaCreate(Request $request)
    {
        $customer = Customer::find($request->customer_id);
        $wh = warehouse::find($request->warehouse_id);
        $proforma_date = $request->proforma_date ?? $request->quotation_date;
        $proforma_number = $request->proforma_number ?? $request->quotation_number;

        $warehouseProducts = WarehouseProduct::where('created_by', '=', Auth::user()->creatorId())
            ->where('warehouse_id', $request->warehouse_id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', Auth::user()->creatorId())
            ->whereIn('id', $warehouseProducts)->where('type', '!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');

        $warehouse = $wh;
        $quotation_date = $proforma_date;
        $quotation_number = $proforma_number;

        return view('proforma.proforma_create', compact('customer', 'warehouse', 'quotation_date', 'quotation_number', 'product_services'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'customer_id' => 'required',
            'warehouse_id' => 'required',
            'proforma_date' => 'nullable|date',
            'quotation_date' => 'nullable|date',
            'items' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $customer = Customer::find($request->customer_id) ?? Customer::where('name', $request->customer_id)->where('created_by', Auth::user()->creatorId())->first();
        $warehouse = warehouse::find($request->warehouse_id) ?? warehouse::where('name', $request->warehouse_id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $customer || ! $warehouse) {
            return redirect()->back()->with('error', __('Invalid customer or warehouse.'));
        }

        $proforma = new Proforma();
        $proforma->proforma_id = $this->nextProformaNumber();
        $proforma->quotation_id = null;
        $proforma->customer_id = $customer->id;
        $proforma->warehouse_id = $warehouse->id;
        $proforma->proforma_date = $request->proforma_date ?? $request->quotation_date ?? now()->toDateString();
        $proforma->status = 0;
        $proforma->category_id = 0;
        $proforma->created_by = Auth::user()->creatorId();
        $proforma->save();

        foreach ($request->items as $row) {
            $line = new ProformaProduct();
            $line->proforma_id = $proforma->id;
            $line->product_id = $row['item'];
            $line->price = $row['price'];
            $line->quantity = $row['quantity'];
            $line->tax = $row['tax'];
            $line->discount = $row['discount'];
            $line->description = $row['description'] ?? null;
            $line->save();
        }

        return redirect()->route('proforma.index')->with('success', __('Proforma successfully created.'));
    }

    public function show($ids)
    {
        if (! Auth::user()->can('show quotation') && Auth::user()->type != 'company') {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($ids);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Proforma Not Found.'));
        }

        $proforma = Proforma::find($id);
        if (! $proforma || (int) $proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $customer = $proforma->customer;
        $iteams = $proforma->items;

        return view('proforma.view', compact('proforma', 'customer', 'iteams'));
    }

    public function edit($ids)
    {
        if (! Auth::user()->can('edit quotation')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        try {
            $id = Crypt::decrypt($ids);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something went wrong.'));
        }

        $proforma = Proforma::find($id);
        if (! $proforma || (int) $proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $customer = Customer::where('id', $proforma->customer_id)->first();
        $warehouse = warehouse::where('id', $proforma->warehouse_id)->first();
        $warehouseProducts = WarehouseProduct::where('created_by', '=', Auth::user()->creatorId())
            ->where('warehouse_id', $proforma->warehouse_id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', Auth::user()->creatorId())
            ->whereIn('id', $warehouseProducts)->where('type', '!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');
        $proforma_number = Auth::user()->proformaNumberFormat($this->nextProformaNumber());
        $quotation_number = Auth::user()->proformaNumberFormat($proforma->proforma_id);

        return view('proforma.edit', compact('customer', 'product_services', 'warehouse', 'proforma_number', 'quotation_number', 'proforma'));
    }

    public function update(Request $request, Proforma $proforma)
    {
        if (! Auth::user()->can('edit quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $proforma->is_invoice_converted === 1) {
            return redirect()->back()->with('error', __('This proforma is already converted to an invoice and cannot be edited.'));
        }
        if ((int) $proforma->is_bill_converted === 1) {
            return redirect()->back()->with('error', __('This proforma is already converted to a supplier bill and cannot be edited.'));
        }
        if ((int) $proforma->is_purchase_converted === 1) {
            return redirect()->back()->with('error', __('This proforma is already converted to a purchase order and cannot be edited.'));
        }

        $validator = \Validator::make($request->all(), [
            'customer_id' => 'required',
            'proforma_date' => 'nullable|date',
            'quotation_date' => 'nullable|date',
            'items' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->route('proforma.index')->with('error', $validator->getMessageBag()->first());
        }

        $customer = Customer::find($request->customer_id) ?? Customer::where('name', $request->customer_id)->where('created_by', Auth::user()->creatorId())->first();
        $warehouse = warehouse::find($request->warehouse_id) ?? warehouse::where('name', $request->warehouse_id)->where('created_by', Auth::user()->creatorId())->first();
        if (! $customer || ! $warehouse) {
            return redirect()->back()->with('error', __('Invalid customer or warehouse.'));
        }

        $proforma->customer_id = $customer->id;
        $proforma->warehouse_id = $warehouse->id;
        $proforma->proforma_date = $request->proforma_date ?? $request->quotation_date ?? $proforma->proforma_date;
        $proforma->save();

        foreach ($request->items as $row) {
            $line = ProformaProduct::find($row['id'] ?? null);
            if ($line === null) {
                $line = new ProformaProduct();
                $line->proforma_id = $proforma->id;
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

        return redirect()->route('proforma.index')->with('success', __('Proforma successfully updated.'));
    }

    public function destroy(Proforma $proforma)
    {
        if (! Auth::user()->can('delete quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $proforma->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ((int) $proforma->is_invoice_converted === 1) {
            return redirect()->back()->with('error', __('Cannot delete a proforma that was converted to an invoice.'));
        }
        if ((int) $proforma->is_bill_converted === 1) {
            return redirect()->back()->with('error', __('Cannot delete a proforma linked to a supplier bill.'));
        }
        if ((int) $proforma->is_purchase_converted === 1) {
            return redirect()->back()->with('error', __('Cannot delete a proforma linked to a purchase order.'));
        }

        if ($proforma->quotation_id) {
            Quotation::where('id', $proforma->quotation_id)->update(['converted_proforma_id' => null]);
        }
        ProformaProduct::where('proforma_id', $proforma->id)->delete();
        $proforma->delete();

        return redirect()->route('proforma.index')->with('success', __('Proforma successfully deleted.'));
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
        $salePrice = $product->sale_price;
        $quantity = 1;
        $taxPrice = ($taxRate / 100) * ($salePrice * $quantity);
        $data['totalAmount'] = ($salePrice * $quantity);
        $data['productquantity'] = $product->getQuantity();

        return json_encode($data);
    }

    public function productDestroy(Request $request)
    {
        if (! Auth::user()->can('delete quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        ProformaProduct::where('id', '=', $request->id)->delete();

        return redirect()->back()->with('success', __('Line removed.'));
    }

    public function items(Request $request)
    {
        $pid = $request->proforma_id ?? $request->quotation_id;
        $items = ProformaProduct::where('proforma_id', $pid)->where('product_id', $request->product_id)->first();

        return json_encode($items);
    }

    public function productQuantity(Request $request)
    {
        $product = ProductService::find($request->item_id);
        $productquantity = $product ? $product->getQuantity() : 0;

        return json_encode($productquantity);
    }

    /**
     * One-step: Quotation → Proforma (copy lines).
     */
    public function createFromQuotation($encryptedId)
    {
        if (! Auth::user()->can('convert quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $qid = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid quotation.'));
        }

        $quotation = Quotation::with('items')->where('created_by', Auth::user()->creatorId())->find($qid);
        if (! $quotation) {
            return redirect()->back()->with('error', __('Quotation not found.'));
        }
        if ((int) $quotation->is_converted === 1) {
            return redirect()->back()->with('error', __('This quotation was already converted to POS.'));
        }
        if (! empty($quotation->converted_proforma_id)) {
            return redirect()->route('proforma.show', Crypt::encrypt($quotation->converted_proforma_id))
                ->with('info', __('Proforma already exists for this quotation.'));
        }

        $proforma = DB::transaction(function () use ($quotation) {
            $p = new Proforma();
            $p->proforma_id = $this->nextProformaNumber();
            $p->quotation_id = $quotation->id;
            $p->customer_id = $quotation->customer_id;
            $p->warehouse_id = $quotation->warehouse_id;
            $p->proforma_date = $quotation->quotation_date ?? now()->toDateString();
            $p->status = 0;
            $p->category_id = $quotation->category_id ?? 0;
            $p->created_by = Auth::user()->creatorId();
            $p->save();

            foreach ($quotation->items as $row) {
                $line = new ProformaProduct();
                $line->proforma_id = $p->id;
                $line->product_id = $row->product_id;
                $line->price = $row->price;
                $line->quantity = $row->quantity;
                $line->tax = $row->tax;
                $line->discount = $row->discount;
                $line->description = $row->description;
                $line->save();
            }

            $quotation->converted_proforma_id = $p->id;
            $quotation->save();

            return $p;
        });

        return redirect()->route('proforma.show', Crypt::encrypt($proforma->id))->with('success', __('Proforma created from quotation.'));
    }

    /**
     * Proforma → Purchase order (stock) → Bill → Payment.
     */
    public function convertToPurchaseForm($encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid proforma.'));
        }

        $proforma = Proforma::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $proforma) {
            return redirect()->back()->with('error', __('Proforma not found.'));
        }
        if ((int) $proforma->is_purchase_converted === 1 && ! empty($proforma->converted_purchase_id)) {
            return redirect()->route('purchase.show', Crypt::encrypt($proforma->converted_purchase_id))
                ->with('info', __('Purchase order already exists.'));
        }

        $venders = Vender::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $venders->prepend(__('Select Vendor'), '');
        $warehouses = warehouse::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $warehouses->prepend(__('Select Warehouse'), '');
        $category = ProductServiceCategory::where('created_by', Auth::user()->creatorId())
            ->where('type', 'expense')->orderBy('id')->first();

        return view('proforma.convert_purchase', compact('proforma', 'venders', 'warehouses', 'category'));
    }

    public function convertToPurchaseStore(Request $request, $encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid proforma.'));
        }

        $proforma = Proforma::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $proforma) {
            return redirect()->back()->with('error', __('Proforma not found.'));
        }
        if ((int) $proforma->is_purchase_converted === 1 && ! empty($proforma->converted_purchase_id)) {
            return redirect()->route('purchase.show', Crypt::encrypt($proforma->converted_purchase_id))
                ->with('info', __('Purchase order already exists.'));
        }

        $validator = \Validator::make($request->all(), [
            'vender_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'purchase_date' => 'required|date',
            'category_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first())->withInput();
        }

        $items = [];
        foreach ($proforma->items as $row) {
            $product = ProductService::find($row->product_id);
            $price = $product && (float) $product->purchase_price > 0
                ? (float) $product->purchase_price
                : (float) $row->price;

            $items[] = [
                'item' => $row->product_id,
                'quantity' => $row->quantity,
                'price' => $price,
                'tax' => $row->tax,
                'discount' => $row->discount,
                'description' => $row->description,
                'product_variant_id' => '',
                'row_no' => '',
                'shelf_no' => '',
                'expiry_date' => '',
                'batch_no' => '',
                'mfg_date' => '',
                'identifiers' => '',
            ];
        }

        if ($items === []) {
            return redirect()->back()->with('error', __('Proforma has no line items.'));
        }

        $req = Request::create('/purchase', 'POST', [
            'vender_id' => $request->vender_id,
            'warehouse_id' => $request->warehouse_id,
            'purchase_date' => $request->purchase_date,
            'category_id' => $request->category_id,
            'purchase_number' => 0,
            'items' => $items,
            'proforma_id' => $proforma->id,
            '_token' => csrf_token(),
        ]);
        $req->setUserResolver(fn () => Auth::user());

        return app(PurchaseController::class)->store($req);
    }

    /**
     * Legacy: Proforma → Supplier bill (prefer Proforma → PO → Bill).
     */
    public function convertToBillForm($encryptedId)
    {
        if (! Auth::user()->can('create bill')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid proforma.'));
        }

        $proforma = Proforma::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $proforma) {
            return redirect()->back()->with('error', __('Proforma not found.'));
        }
        if ((int) $proforma->is_purchase_converted === 1 && ! empty($proforma->converted_purchase_id)) {
            return redirect()->route('purchase.show', Crypt::encrypt($proforma->converted_purchase_id))
                ->with('info', __('Convert the purchase order to a vendor bill instead.'));
        }
        if ((int) $proforma->is_bill_converted === 1) {
            return redirect()->route('bill.show', Crypt::encrypt($proforma->converted_bill_id))
                ->with('info', __('Supplier bill already exists.'));
        }

        $venders = Vender::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $venders->prepend(__('Select Vendor'), '');
        $category = ProductServiceCategory::where('created_by', Auth::user()->creatorId())
            ->where('type', 'expense')->orderBy('id')->first();

        return view('proforma.convert_bill', compact('proforma', 'venders', 'category'));
    }

    public function convertToBillStore(Request $request, $encryptedId)
    {
        if (! Auth::user()->can('create bill')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid proforma.'));
        }

        $proforma = Proforma::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $proforma) {
            return redirect()->back()->with('error', __('Proforma not found.'));
        }
        if ((int) $proforma->is_bill_converted === 1) {
            return redirect()->route('bill.show', Crypt::encrypt($proforma->converted_bill_id))
                ->with('info', __('Supplier bill already exists.'));
        }

        $validator = \Validator::make($request->all(), [
            'vender_id' => 'required|integer',
            'category_id' => 'required|integer',
            'bill_date' => 'required|date',
            'due_date' => 'required|date',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $items = [];
        foreach ($proforma->items as $row) {
            $product = ProductService::find($row->product_id);
            $price = $product && (float) $product->purchase_price > 0
                ? (float) $product->purchase_price
                : (float) $row->price;

            $items[] = [
                'item' => $row->product_id,
                'quantity' => $row->quantity,
                'price' => $price,
                'tax' => $row->tax,
                'discount' => $row->discount,
                'description' => $row->description,
            ];
        }

        $req = Request::create('/bill', 'POST', [
            'vender_id' => $request->vender_id,
            'bill_date' => $request->bill_date,
            'due_date' => $request->due_date,
            'category_id' => $request->category_id,
            'order_number' => 0,
            'items' => $items,
            'proforma_id' => $proforma->id,
            'suppress_inventory' => 0,
            '_token' => csrf_token(),
        ]);
        $req->setUserResolver(fn () => Auth::user());

        return app(BillController::class)->store($req);
    }

    protected function nextProformaNumber(): int
    {
        $latest = Proforma::where('created_by', '=', Auth::user()->creatorId())->latest('id')->first();
        if (! $latest) {
            return 1;
        }

        return (int) $latest->proforma_id + 1;
    }
}
