<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Illuminate\Http\Request;
use App\Models\PosPayment;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Utility;
use App\Models\ProductServiceCategory;
use App\Models\ProductService;
use App\Models\WarehouseProduct;
use App\Models\warehouse;
use App\Models\QuotationProduct;
use App\Models\Vender;
use Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use DB;

class QuotationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (Auth::user()->can('manage quotation'))
        {
            $quotations      = Quotation::where('created_by', \Auth::user()->creatorId())->with(['customer', 'warehouse', 'proformaFromQuotation'])->get();

            return view('quotation.index',compact('quotations'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if(\Auth::user()->can('create quotation'))
        {
            $customers     = Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $customers->prepend('Select Customer', '');

            $venders = Vender::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $venders->prepend('Select Vendor', '');

            $warehouse     = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $warehouse->prepend('Select Warehouse', '');

            // $product_services = ['--'];

            $quotation_number = \Auth::user()->quotationNumberFormat($this->quotationNumber());

            return view('quotation.create', compact('customers', 'venders', 'warehouse' , 'quotation_number'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }


    public function quotationCreate(Request $request)
    {
        $customer = $request->filled('customer_id') ? Customer::find($request->customer_id) : null;
        $vender = $request->filled('vender_id') ? Vender::find($request->vender_id) : null;
        $warehouse = warehouse::find($request->warehouse_id);
        $quotation_date = $request->quotation_date;
        $quotation_number = $request->quotation_number;


        $warehouseProducts = WarehouseProduct::where('created_by', '=', \Auth::user()->creatorId())->where('warehouse_id',$request->warehouse_id)->get()->pluck('product_id')->toArray();
        $product_services = ProductService::where('created_by', \Auth::user()->creatorId())->whereIn('id',$warehouseProducts)->where('type','!=', 'service')->get()->pluck('name', 'id');
        $product_services->prepend(' -- ', '');

        return view('quotation.quotation_create', compact('customer', 'vender', 'warehouse' ,'quotation_date' , 'quotation_number','product_services'));
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if(\Auth::user()->can('create quotation'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    'customer_id' => 'required',
                    'warehouse_id' => 'required',
                    'quotation_date' => 'required',
                    'items' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $customer = Customer::where('name',$request->customer_id)->first();
            $warehouse = warehouse::where('name',$request->warehouse_id)->first();

            $quotations                 = new Quotation();
            $quotations->quotation_id    = $this->quotationNumber();
            $quotations->customer_id      = $customer->id;
            $quotations->warehouse_id      = $warehouse->id;
            $quotations->quotation_date  = $request->quotation_date;
            $quotations->status         =  0;
            $quotations->category_id    =  0;
            $quotations->created_by     = \Auth::user()->creatorId();
            $quotations->save();

            $products = $request->items;
            if (! is_array($products) || count($products) === 0) {
                return redirect()->back()->with('error', __('Please add at least one product.'))->withInput();
            }

            for($i = 0; $i < count($products); $i++)
            {
                if (empty($products[$i]['item'])) {
                    continue;
                }
                $tax = $products[$i]['tax'] ?? '0';
                if (is_array($tax)) {
                    $tax = implode(',', array_filter($tax, fn ($v) => $v !== null && $v !== ''));
                }
                if ($tax === null || $tax === '') {
                    $tax = '0';
                }

                $quotationItems              = new QuotationProduct();
                $quotationItems->quotation_id    = $quotations->id;
                $quotationItems->product_id = $products[$i]['item'];
                $quotationItems->product_variant_id = ! empty($products[$i]['product_variant_id']) ? (int) $products[$i]['product_variant_id'] : null;
                $quotationItems->price      = $products[$i]['price'] ?? 0;
                $quotationItems->quantity   = $products[$i]['quantity'] ?? 0;
                $quotationItems->tax       = (string) $tax;
                $quotationItems->discount        = $products[$i]['discount'] ?? 0;
                $quotationItems->description = $products[$i]['description'] ?? '';
                $quotationItems->save();
            }

            return redirect()->route('quotation.index', $quotations->id)->with('success', __('Quotation successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($ids)
    {

        if (\Auth::user()->can('show quotation') || \Auth::user()->type == 'company') {
            try {
                $id = Crypt::decrypt($ids);
            } catch (\Throwable $th) {
                return redirect()->back()->with('error', __('Quotation Not Found.'));
            }

            $id = Crypt::decrypt($ids);

            $quotation = Quotation::find($id);

            if ($quotation->created_by == \Auth::user()->creatorId()) {
                $quotationPayment = PosPayment::where('pos_id', $quotation->id)->first();
                $customer = $quotation->customer;
                $iteams = $quotation->items;

                return view('quotation.view', compact('quotation', 'customer', 'iteams', 'quotationPayment'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($ids)
    {
        if(\Auth::user()->can('edit quotation'))
        {
            try{
                $id   = Crypt::decrypt($ids);
            } catch (\Exception $e){
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
            $quotation     = Quotation::find($id);

            $customer = Customer::where('id',$quotation->customer_id)->first();
            $warehouse = warehouse::where('id',$quotation->warehouse_id)->first();

            $warehouseProducts = WarehouseProduct::where('created_by', '=', \Auth::user()->creatorId())->where('warehouse_id',$quotation->warehouse_id)->get()->pluck('product_id')->toArray();
            $product_services = ProductService::where('created_by', \Auth::user()->creatorId())->whereIn('id',$warehouseProducts)->where('type','!=', 'service')->get()->pluck('name', 'id');
            $product_services->prepend(' -- ', '');

            $quotation_number = \Auth::user()->quotationNumberFormat($this->quotationNumber());

            return view('quotation.edit', compact('customer', 'product_services','warehouse' , 'quotation_number' , 'quotation'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Quotation $quotation)
    {
        if(\Auth::user()->can('edit quotation'))
        {

            if($quotation->created_by == \Auth::user()->creatorId())
            {
                $validator = \Validator::make(
                    $request->all(), [
                        'customer_id' => 'required',
                        'quotation_date' => 'required',
                        'items' => 'required',
                    ]
                );
                if($validator->fails())
                {
                    $messages = $validator->getMessageBag();

                    return redirect()->route('quotation.index')->with('error', $messages->first());
                }
                $customer = Customer::where('name',$request->customer_id)->first();
                $warehouse = warehouse::where('name',$request->warehouse_id)->first();

                $quotation->customer_id      = $customer->id;
                $quotation->warehouse_id      = $warehouse->id;
                $quotation->quotation_date  = $request->quotation_date;
                $quotation->status         =  0;
                $quotation->category_id    =  0;
                $quotation->created_by     = \Auth::user()->creatorId();
                $quotation->save();
                $products = $request->items;

                for($i = 0; $i < count($products); $i++)
                {
                    $quotationProduct = QuotationProduct::find($products[$i]['id']);

                    if($quotationProduct == null)
                    {
                        $quotationProduct             = new QuotationProduct();
                        $quotationProduct->quotation_id    = $quotation->id;

                    }
                    if(isset($products[$i]['item']))
                    {
                        $quotationProduct->product_id = $products[$i]['item'];
                    }

                    $quotationProduct->quantity    = $products[$i]['quantity'];
                    $tax = $products[$i]['tax'] ?? '0';
                    if (is_array($tax)) {
                        $tax = implode(',', array_filter($tax, fn ($v) => $v !== null && $v !== ''));
                    }
                    if ($tax === null || $tax === '') {
                        $tax = '0';
                    }
                    $quotationProduct->tax         = (string) $tax;
                    $quotationProduct->discount    = $products[$i]['discount'] ?? 0;
                    $quotationProduct->price       = $products[$i]['price'];
                    $quotationProduct->description = $products[$i]['description'] ?? '';
                    $quotationProduct->product_variant_id = ! empty($products[$i]['product_variant_id'])
                        ? (int) $products[$i]['product_variant_id']
                        : null;
                    $quotationProduct->save();

                }

                return redirect()->route('quotation.index')->with('success', __('Quotation successfully updated.'));
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
     */
    public function destroy(Quotation $quotation)
    {
        if(\Auth::user()->can('delete quotation'))
        {
            if($quotation->created_by == \Auth::user()->creatorId())
            {
                if (! empty($quotation->converted_proforma_id)) {
                    $pf = \App\Models\Proforma::find($quotation->converted_proforma_id);
                    if ($pf && (int) $pf->is_invoice_converted !== 1) {
                        \App\Models\ProformaProduct::where('proforma_id', $pf->id)->delete();
                        $pf->delete();
                    }
                }

                $quotation->delete();
                QuotationProduct::where('quotation_id', '=', $quotation->id)->delete();


                return redirect()->route('quotation.index')->with('success', __('Quotation successfully deleted.'));
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

    function quotationNumber()
    {
        $latest = Quotation::where('created_by', '=', \Auth::user()->creatorId())->latest()->first();
        if(!$latest)
        {
            return 1;
        }

        return $latest->quotation_id + 1;
    }

    public function product(Request $request)
    {
        $data['product']     = $product = ProductService::find($request->product_id);
        $data['unit']        = !empty($product->unit) ? $product->unit->name : '';
        $data['taxRate']     = $taxRate = !empty($product->tax_id) ? $product->taxRate($product->tax_id) : 0;
        $data['taxes']       = !empty($product->tax_id) ? $product->tax($product->tax_id) : 0;
        $salePrice           = $product->sale_price;
        $quantity            = 1;
        $taxPrice            = ($taxRate / 100) * ($salePrice * $quantity);
        $data['totalAmount'] = ($salePrice * $quantity);

        $product = ProductService::find($request->product_id);
        $productquantity = 0;

        if ($product) {
            $data['productquantityhj'] = 'gy';
            $productquantity = $product->getQuantity();
        }
        $data['productquantity'] = $productquantity;

        $data = array_merge($data, $product->variantsForDocumentJson('sale_price'));
        $data = array_merge($data, \App\Services\ProductOptionService::productJsonExtras($product));

        return json_encode($data);
    }

    public function productDestroy(Request $request)
    {

        if(\Auth::user()->can('delete quotation'))
        {

            QuotationProduct::where('id', '=', $request->id)->delete();

            return redirect()->back()->with('success', __('Quotation product successfully deleted.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function items(Request $request)
    {
        $items = QuotationProduct::where('quotation_id', $request->quotation_id)->where('product_id', $request->product_id)->first();

        return json_encode($items);
    }

    public function productQuantity(Request $request)
    {
        $product = ProductService::find($request->item_id);
        $productquantity = 0;

        if ($product) {
            $productquantity = $product->getQuantity();
        }

        return json_encode($productquantity);

    }

    public function previewQuotation($template, $color)
    {

        $objUser = \Auth::user();
        $settings = Utility::settings();

        $quotation = new Quotation();
        $quotationPayment = new posPayment();
        $quotationPayment->amount = 360;
        $quotationPayment->discount = 100;

        $customer = new \stdClass();
        $customer->email = '<Email>';
        $customer->shipping_name = '<Customer Name>';
        $customer->shipping_country = '<Country>';
        $customer->shipping_state = '<State>';
        $customer->shipping_city = '<City>';
        $customer->shipping_phone = '<Customer Phone Number>';
        $customer->shipping_zip = '<Zip>';
        $customer->shipping_address = '<Address>';
        $customer->billing_name = '<Customer Name>';
        $customer->billing_country = '<Country>';
        $customer->billing_state = '<State>';
        $customer->billing_city = '<City>';
        $customer->billing_phone = '<Customer Phone Number>';
        $customer->billing_zip = '<Zip>';
        $customer->billing_address = '<Address>';

        $totalTaxPrice = 0;
        $taxesData = [];
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $item = new \stdClass();
            $item->name = 'Item ' . $i;
            $item->quantity = 1;
            $item->tax = 5;
            $item->discount = 50;
            $item->price = 100;

            $taxes = [
                'Tax 1',
                'Tax 2',
            ];

            $itemTaxes = [];
            foreach ($taxes as $k => $tax) {
                $taxPrice = 10;
                $totalTaxPrice += $taxPrice;
                $itemTax['name'] = 'Tax ' . $k;
                $itemTax['rate'] = '10';
                $itemTax['price'] = '$10';
                $itemTax['tax_price'] = $taxPrice;
                $itemTaxes[] = $itemTax;
                if (array_key_exists('Tax ' . $k, $taxesData)) {
                    $taxesData['Tax ' . $k] = $taxesData['Tax ' . $k] + $taxPrice;
                } else {
                    $taxesData['Tax ' . $k] = $taxPrice;
                }
            }
            $item->itemTax = $itemTaxes;
            $item->sku = 'SKU-' . $i;
            $items[] = $item;
        }

        $quotation->quotation_id = 1;

        $quotation->issue_date = date('Y-m-d H:i:s');
        $quotation->itemData = $items;

        $quotation->totalTaxPrice = 60;
        $quotation->totalQuantity = 3;
        $quotation->totalRate = 300;
        $quotation->totalDiscount = 10;
        $quotation->taxesData = $taxesData;
        $quotation->created_by = $objUser->creatorId();

        $preview = 1;
        $color = '#' . $color;
        $font_color = Utility::getFontColor($color);

        $logo = asset(Storage::url('uploads/logo/'));

        $company_logo = Utility::getValByName('company_logo_dark');
        $settings_data = \App\Models\Utility::settingsById($quotation->created_by);
        $quotation_logo = isset($settings_data['quotation_logo']) ? $settings_data['quotation_logo'] : '';

        if (isset($quotation_logo) && !empty($quotation_logo)) {
            $img = Utility::get_file('quotation_logo/') . $quotation_logo;
        } else {
            $img = asset($logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png'));
        }

        return view('quotation.templates.' . $template, compact('quotation', 'preview', 'color', 'img', 'settings', 'customer', 'font_color', 'quotationPayment'));
    }

    public function saveQuotationTemplateSettings(Request $request)
    {

        $post = $request->all();
        unset($post['_token']);

        if (isset($post['quotation_template']) && (!isset($post['quotation_color']) || empty($post['quotation_color']))) {
            $post['quotation_color'] = "ffffff";
        }
        $post['quotation_receipt_layout'] = Utility::normalizeReceiptLayout($request->input('quotation_receipt_layout'));

        if ($request->quotation_logo) {
            $dir = 'quotation_logo/';
            $quotation_logo = \Auth::user()->id . '_quotation_logo.png';
            $validation = [
                'mimes:' . 'png',
                'max:' . '20480',
            ];
            $path = Utility::upload_file($request, 'quotation_logo', $quotation_logo, $dir, $validation);
            if ($path['flag'] == 0) {
                return redirect()->back()->with('error', __($path['msg']));
            }
            $post['quotation_logo'] = $quotation_logo;
        }

        foreach ($post as $key => $data) {
            \DB::insert(
                'insert into settings (`value`, `name`,`created_by`) values (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ', [
                    $data,
                    $key,
                    \Auth::user()->creatorId(),
                ]
            );
        }

        return redirect()->back()->with('success', __('Quotation Setting updated successfully'));
    }

    public function printView(Request $request)
    {

        $sess = session()->get('pos');

        $user = Auth::user();
        $settings = Utility::settings();

        $customer = Customer::where('name', '=', $request->vc_name)->where('created_by', $user->creatorId())->first();
        $warehouse = warehouse::where('id', '=', $request->warehouse_name)->where('created_by', $user->creatorId())->first();

        $details = [
            'pos_id' => $user->quotationNumberFormat($this->quotationNumber()),
            'customer' => $customer != null ? $customer->toArray() : [],
            'warehouse' => $warehouse != null ? $warehouse->toArray() : [],
            'user' => $user != null ? $user->toArray() : [],
            'date' => date('Y-m-d'),
            'pay' => 'show',
        ];

        if (!empty($details['customer'])) {
            $warehousedetails = '<h7 class="text-dark">' . ucfirst($details['warehouse']['name']) . '</p></h7>';
            $details['customer']['billing_state'] = $details['customer']['billing_state'] != '' ? ", " . $details['customer']['billing_state'] : '';
            $details['customer']['shipping_state'] = $details['customer']['shipping_state'] != '' ? ", " . $details['customer']['shipping_state'] : '';
            $customerdetails = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['billing_phone'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['billing_address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['billing_city'] . $details['customer']['billing_state'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['billing_country'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['billing_zip'] . '</p></h6>';
            $shippdetails = '<h6 class="text-dark"><b>' . ucfirst($details['customer']['name']) . '</b>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['shipping_phone'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['shipping_address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['shipping_city'] . $details['customer']['shipping_state'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['shipping_country'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $details['customer']['shipping_zip'] . '</p></h6>';

        } else {
            $customerdetails = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b><h2>';
            $warehousedetails = '<h7 class="text-dark">' . ucfirst($details['warehouse']['name']) . '</p></h7>';
            $shippdetails = '-';

        }

        $settings['company_telephone'] = $settings['company_telephone'] != '' ? ", " . $settings['company_telephone'] : '';
        $settings['company_state'] = $settings['company_state'] != '' ? ", " . $settings['company_state'] : '';

        $userdetails = '<h6 class="text-dark"><b>' . ucfirst($details['user']['name']) . ' </b> <h2  class="font-weight-normal">' . '<p class="m-0 font-weight-normal">' . $settings['company_name'] . $settings['company_telephone'] . '</p>' . '<p class="m-0 font-weight-normal">' . $settings['company_address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $settings['company_city'] . $settings['company_state'] . '</p>' . '<p class="m-0 font-weight-normal">' . $settings['company_country'] . '</p>' . '<p class="m-0 font-weight-normal">' . $settings['company_zipcode'] . '</p></h2>';

        $details['customer']['details'] = $customerdetails;
        $details['warehouse']['details'] = $warehousedetails;
            //
        $details['customer']['shippdetails'] = $shippdetails;

        $details['user']['details'] = $userdetails;

        $mainsubtotal = 0;
        $sales = [];

        foreach ($sess as $key => $value) {

            $subtotal = $value['price'] * $value['quantity'];
            $tax = ($subtotal * $value['tax']) / 100;
            $sales['data'][$key]['name'] = $value['name'];
            $sales['data'][$key]['quantity'] = $value['quantity'];
            $sales['data'][$key]['price'] = Auth::user()->priceFormat($value['price']);
            $sales['data'][$key]['tax'] = $value['tax'] . '%';
            $sales['data'][$key]['product_tax'] = $value['product_tax'];
            $sales['data'][$key]['tax_amount'] = Auth::user()->priceFormat($tax);
            $sales['data'][$key]['subtotal'] = Auth::user()->priceFormat($value['subtotal']);
            $mainsubtotal += $value['subtotal'];
        }

        $discount = !empty($request->discount) ? $request->discount : 0;
        $sales['discount'] = Auth::user()->priceFormat($discount);
        $total = $mainsubtotal - $discount;
        $sales['sub_total'] = Auth::user()->priceFormat($mainsubtotal);
        $sales['total'] = Auth::user()->priceFormat($total);

        //for barcode

        $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())->get();
        $barcode = [
            'barcodeType' => Auth::user()->barcodeType(),
            'barcodeFormat' => Auth::user()->barcodeFormat(),
        ];

        return view('quotation.printview', compact('details', 'sales', 'customer', 'productServices', 'barcode'));

    }

    /**
     * Accounting flow: Quotation → Proforma (POS conversion remains separate on quotation index).
     */
    public function convert($id)
    {
        if (! \Auth::user()->can('convert quotation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return redirect()->route('proforma.create-from-quotation', \Crypt::encrypt((int) $id));
    }

    /**
     * Quotation → Invoice (direct). Can run alongside PI / PO.
     */
    public function convertToInvoiceForm($encryptedId)
    {
        if (! \Auth::user()->can('create invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid quotation.'));
        }

        $quotation = Quotation::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $quotation) {
            return redirect()->back()->with('error', __('Quotation not found.'));
        }
        if (! empty($quotation->converted_invoice_id)) {
            return redirect()->route('invoice.show', Crypt::encrypt($quotation->converted_invoice_id))
                ->with('info', __('Invoice already exists for this quotation.'));
        }

        $category = ProductServiceCategory::where('created_by', Auth::user()->creatorId())
            ->where(function ($q) {
                $q->where('type', 'income')->orWhere('type', 1);
            })
            ->orderBy('id')
            ->first();

        $quotationReference = Auth::user()->quotationNumberFormat($quotation->quotation_id);

        return view('quotation.convert_invoice', compact('quotation', 'category', 'quotationReference'));
    }

    public function convertToInvoiceStore(Request $request, $encryptedId)
    {
        if (! \Auth::user()->can('create invoice')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid quotation.'));
        }

        $quotation = Quotation::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $quotation) {
            return redirect()->back()->with('error', __('Quotation not found.'));
        }
        if (! empty($quotation->converted_invoice_id)) {
            return redirect()->route('invoice.show', Crypt::encrypt($quotation->converted_invoice_id))
                ->with('info', __('Invoice already exists for this quotation.'));
        }

        $validator = \Validator::make($request->all(), [
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'category_id' => 'required|integer',
            'client_po_date' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first())->withInput();
        }

        $items = [];
        foreach ($quotation->items as $row) {
            $items[] = [
                'item' => $row->product_id,
                'quantity' => $row->quantity,
                'price' => $row->price,
                'tax' => $row->tax,
                'discount' => $row->discount,
                'description' => $row->description,
                'product_variant_id' => $row->product_variant_id ?: '',
                'identifiers' => '',
            ];
        }
        if ($items === []) {
            return redirect()->back()->with('error', __('Quotation has no line items.'));
        }

        $quotationReference = $request->input('quotation_reference')
            ?: Auth::user()->quotationNumberFormat($quotation->quotation_id);

        $payload = [
            'customer_id' => $quotation->customer_id,
            'issue_date' => $request->issue_date,
            'due_date' => $request->due_date,
            'category_id' => $request->category_id,
            'ref_number' => $request->ref_number ?: $quotationReference,
            'client_po_number' => $request->client_po_number,
            'client_po_date' => $request->client_po_date,
            'client_po_details' => $request->client_po_details,
            'quotation_reference' => $quotationReference,
            'items' => $items,
            'source_quotation_id' => $quotation->id,
            '_token' => csrf_token(),
        ];
        if (! empty($quotation->converted_proforma_id)) {
            $payload['source_proforma_id'] = $quotation->converted_proforma_id;
        }
        if (! empty($quotation->converted_purchase_id)) {
            $payload['source_purchase_id'] = $quotation->converted_purchase_id;
        }

        $req = Request::create('/invoice', 'POST', $payload);
        $req->setUserResolver(fn () => Auth::user());

        return app(InvoiceController::class)->store($req);
    }

    /**
     * Quotation → Purchase order (direct). Can run alongside PI / Invoice.
     */
    public function convertToPurchaseForm($encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid quotation.'));
        }

        $quotation = Quotation::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $quotation) {
            return redirect()->back()->with('error', __('Quotation not found.'));
        }
        if (! empty($quotation->converted_purchase_id)) {
            return redirect()->route('purchase.show', Crypt::encrypt($quotation->converted_purchase_id))
                ->with('info', __('Purchase order already exists for this quotation.'));
        }

        $venders = Vender::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $venders->prepend(__('Select Vendor'), '');
        $warehouses = warehouse::where('created_by', Auth::user()->creatorId())->get()->pluck('name', 'id');
        $warehouses->prepend(__('Select Warehouse'), '');
        $category = ProductServiceCategory::where('created_by', Auth::user()->creatorId())
            ->where('type', 'expense')->orderBy('id')->first();

        return view('quotation.convert_purchase', compact('quotation', 'venders', 'warehouses', 'category'));
    }

    public function convertToPurchaseStore(Request $request, $encryptedId)
    {
        if (! Auth::user()->can('create purchase')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Invalid quotation.'));
        }

        $quotation = Quotation::with('items')->where('created_by', Auth::user()->creatorId())->find($id);
        if (! $quotation) {
            return redirect()->back()->with('error', __('Quotation not found.'));
        }
        if (! empty($quotation->converted_purchase_id)) {
            return redirect()->route('purchase.show', Crypt::encrypt($quotation->converted_purchase_id))
                ->with('info', __('Purchase order already exists for this quotation.'));
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

        $selected = $request->input('items', []);
        if (! is_array($selected) || $selected === []) {
            return redirect()->back()->with('error', __('Select at least one item.'))->withInput();
        }

        $items = [];
        foreach ($quotation->items as $row) {
            $payload = $selected[$row->id] ?? null;
            if (! is_array($payload) || empty($payload['selected'])) {
                continue;
            }
            $qty = (float) ($payload['quantity'] ?? $row->quantity);
            if ($qty <= 0) {
                return redirect()->back()->with('error', __('Quantity must be greater than zero.'))->withInput();
            }

            $product = ProductService::find($row->product_id);
            $price = $product && (float) $product->purchase_price > 0
                ? (float) $product->purchase_price
                : (float) $row->price;
            $discount = (float) $row->discount;
            $originalQty = (float) $row->quantity;
            if ($originalQty > 0 && abs($qty - $originalQty) > 0.00001) {
                $discount = $discount * ($qty / $originalQty);
            }

            $items[] = [
                'item' => $row->product_id,
                'quantity' => $qty,
                'price' => $price,
                'tax' => $row->tax,
                'discount' => $discount,
                'description' => $row->description,
                'product_variant_id' => $row->product_variant_id ?: '',
                'allow_default_variant' => empty($row->product_variant_id) ? 1 : 0,
                'row_no' => '',
                'shelf_no' => '',
                'expiry_date' => '',
                'batch_no' => '',
                'mfg_date' => '',
                'identifiers' => '',
            ];
        }
        if ($items === []) {
            return redirect()->back()->with('error', __('Select at least one item.'))->withInput();
        }

        $payload = [
            'vender_id' => $request->vender_id,
            'warehouse_id' => $request->warehouse_id,
            'purchase_date' => $request->purchase_date,
            'category_id' => $request->category_id,
            'purchase_number' => 0,
            'items' => $items,
            'quotation_id' => $quotation->id,
            '_token' => csrf_token(),
        ];
        if (! empty($quotation->converted_proforma_id)) {
            $payload['proforma_id'] = $quotation->converted_proforma_id;
        }

        $req = Request::create('/purchase', 'POST', $payload);
        $req->setUserResolver(fn () => Auth::user());

        return app(PurchaseController::class)->store($req);
    }

    public function quotation($quotation_Id)
    {
        $settings = Utility::settings();
        try{
            $quotationId = Crypt::decrypt($quotation_Id);
        } catch (\Exception $e){
            return redirect()->back()->with('error', __('Something went wrong.'));
        }
        $quotation = Quotation::where('id', $quotationId)->first();


        $data = \DB::table('settings');
        $data = $data->where('created_by', '=', $quotation->created_by);
        $data1 = $data->get();

        foreach ($data1 as $row) {
            $settings[$row->name] = $row->value;
        }

        $customer = $quotation->customer;

        $totalTaxPrice = 0;
        $totalQuantity = 0;
        $totalRate = 0;
        $totalDiscount = 0;
        $taxesData = [];
        $items = [];

        foreach ($quotation->items as $product) {

            $item = new \stdClass();
            $item->name = !empty($product->product()) ? $product->product()->name : '';
            $item->quantity = $product->quantity;
            $item->tax = $product->tax;
            $item->discount = $product->discount;
            $item->price = $product->price;
            $item->description = $product->description;
            $item->sku = !empty($product->product()) ? ($product->product()->sku ?? '-') : '-';
            $totalQuantity += $item->quantity;
            $totalRate += $item->price;
            $totalDiscount += $item->discount;
            $taxes = Utility::tax($product->tax);
            $itemTaxes = [];
            if (!empty($item->tax)) {
                foreach ($taxes as $tax) {
                    $taxPrice = Utility::taxRate($tax->rate, $item->price, $item->quantity);
                    $totalTaxPrice += $taxPrice;

                    $itemTax['name'] = $tax->name;
                    $itemTax['rate'] = $tax->rate . '%';
                    $itemTax['price'] = Utility::priceFormat($settings, $taxPrice);
                    $itemTax['tax_price'] = $taxPrice;
                    $itemTaxes[] = $itemTax;

                    if (array_key_exists($tax->name, $taxesData)) {
                        $taxesData[$tax->name] = $taxesData[$tax->name] + $taxPrice;
                    } else {
                        $taxesData[$tax->name] = $taxPrice;
                    }

                }

                $item->itemTax = $itemTaxes;
            } else {
                $item->itemTax = [];
            }
            $items[] = $item;
        }

        $quotation->itemData = $items;
        $quotation->totalTaxPrice = $totalTaxPrice;
        $quotation->totalQuantity = $totalQuantity;
        $quotation->totalRate = $totalRate;
        $quotation->totalDiscount = $totalDiscount;
        $quotation->taxesData = $taxesData;

        $logo = asset(Storage::url('uploads/logo/'));
        $company_logo = Utility::getValByName('company_logo_dark');
        $quotation_logo = Utility::getValByName('quotation_logo');
        if (isset($quotation_logo) && !empty($quotation_logo)) {
            $img = Utility::get_file('quotation_logo/') . $quotation_logo;
        } else {
            $img = asset($logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png'));
        }

        if ($quotation) {
            $color = '#' . $settings['quotation_color'];
            $font_color = Utility::getFontColor($color);
            $receiptLayout = Utility::normalizeReceiptLayout($settings['quotation_receipt_layout'] ?? 'existing');

            if ($receiptLayout === 'compact1') {
                return view('quotation.receipts.compact1', compact('quotation', 'color', 'settings', 'customer', 'img', 'font_color'));
            }
            if ($receiptLayout === 'compact2') {
                return view('quotation.receipts.compact2', compact('quotation', 'color', 'settings', 'customer', 'img', 'font_color'));
            }

            return view('quotation.templates.' . $settings['quotation_template'], compact('quotation', 'color', 'settings', 'customer', 'img', 'font_color'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }
}
