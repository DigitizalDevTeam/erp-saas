<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\Quotation;
use App\Models\PosBalance;
use App\Services\PosToInvoiceService;
use App\Models\QuotationProduct;
use App\Models\StockReport;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\PosArea;
use App\Models\PosTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PosController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id = 0)
    {
        if (Auth::user()->can('manage pos')) {
            // Phase 5: POS user limit per plan
            $creatorId = Auth::user()->creatorId();
            $owner = User::find($creatorId);
            if ($owner && $owner->plan) {
                $plan = Plan::find($owner->plan);
                if ($plan && $plan->pos == 1 && $plan->max_pos_users >= 0) {
                    $posUserCount = Plan::countPosUsersForCompany($creatorId);
                    if ($posUserCount > $plan->max_pos_users) {
                        return redirect()->back()->with('error', __('POS user limit reached. Please upgrade your plan.'));
                    }
                }
            }
            session()->forget('pos');
            $customers = Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'name');
            $customers->prepend('Walk-in-customer', '');
            $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id');
            $warehouses = warehouse::with('branch')->where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $restaurantBranchIds = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->where('pos_type', Branch::POS_TYPE_RESTAURANT)->pluck('id')->toArray();
            $retailBranchIds = Branch::where('created_by', $creatorId)->where('is_active', true)
                ->where(function ($q) {
                    $q->where('pos_type', Branch::POS_TYPE_RETAIL)->orWhereNull('pos_type');
                })
                ->pluck('id')->toArray();
            $hasRetailBranches = !empty($retailBranchIds);
            $hasRestaurantBranches = !empty($restaurantBranchIds);
            $user = Auth::user();
            $details = [
                'pos_id' => $user->posNumberFormat($this->invoicePosNumber()),
                'customer' => $customers != null ? $customers->toArray() : [],
                'user' => $user != null ? $user->toArray() : [],
                'date' => date('Y-m-d'),
                'pay' => 'show',
            ];

            if ($id != 0) {

                $quotation = Quotation::find($id);

                $customerId = $quotation->customer_id;
                $customerId = Customer::find($customerId);
                $customer = $customerId->name;

                $warehouseId = $quotation->warehouse_id;

                $quotationProduct = QuotationProduct::where('quotation_id', $id)->get();

                foreach ($quotationProduct as $value) {
                    $products = Quotation::quotationProduct($value);
                }
            } else {
                $customer = '';
                $warehouseId = '';
            } 
            return view('pos.index', compact('customers', 'branches', 'warehouses', 'details', 'customer', 'warehouseId', 'id', 'restaurantBranchIds', 'hasRetailBranches', 'hasRestaurantBranches'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Get restaurant layout data: areas with tables for a branch.
     */
    public function getRestaurantLayout(Request $request)
    {
        if (!Auth::user()->can('manage pos')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $branchId = $request->branch_id;
        if (!$branchId) {
            return response()->json(['areas' => []]);
        }
        $areas = PosArea::where('created_by', Auth::user()->creatorId())
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $tables = PosTable::where('created_by', Auth::user()->creatorId())
            ->where('branch_id', $branchId)
            ->with('area')
            ->orderBy('area_id')
            ->orderBy('name')
            ->get();
        $result = $areas->map(function ($area) use ($tables) {
            return [
                'id' => $area->id,
                'name' => $area->name,
                'tables' => $tables->where('area_id', $area->id)->values()->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'status' => $t->status,
                    'capacity' => $t->capacity,
                ])->toArray(),
            ];
        })->toArray();
        // Tables without area
        $tablesNoArea = $tables->whereNull('area_id');
        if ($tablesNoArea->isNotEmpty()) {
            $result[] = [
                'id' => 0,
                'name' => __('Other'),
                'tables' => $tablesNoArea->values()->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'status' => $t->status,
                    'capacity' => $t->capacity,
                ])->toArray(),
            ];
        }
        return response()->json(['areas' => $result]);
    }

    /**
     * Get current user's active (open) shift for a branch.
     */
    public function getActiveShift(Request $request)
    {
        if (!Auth::user()->can('manage pos')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $branchId = $request->has('branch_id') ? (int) $request->branch_id : null;
        if (!$branchId) {
            return response()->json(['shift' => null]);
        }
        $shift = \App\Models\PosShift::where('branch_id', $branchId)
            ->where('user_id', Auth::id())
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', Auth::user()->creatorId())
            ->orderBy('opened_at', 'desc')
            ->first();

        return response()->json([
            'shift' => $shift ? [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at?->format('H:i'),
                'opening_balance' => $shift->opening_balance,
            ] : null,
        ]);
    }

    /**
     * Get all open shifts for a branch (all users). Used in restaurant mode so staff can
     * see every open shift and choose their own or another user's shift for the order.
     */
    public function getBranchOpenShifts(Request $request)
    {
        if (!Auth::user()->can('manage pos')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $branchId = $request->has('branch_id') ? (int) $request->branch_id : null;
        if (!$branchId) {
            return response()->json(['shifts' => []]);
        }
        $currentUserId = Auth::id();
        $shifts = \App\Models\PosShift::where('branch_id', $branchId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', Auth::user()->creatorId())
            ->with('user:id,name')
            ->orderBy('opened_at', 'desc')
            ->get();

        $list = $shifts->map(function ($shift) use ($currentUserId) {
            return [
                'id' => $shift->id,
                'user_id' => $shift->user_id,
                'user_name' => $shift->user ? $shift->user->name : __('Unknown'),
                'opened_at' => $shift->opened_at ? $shift->opened_at->format('H:i') : '',
                'opening_balance' => $shift->opening_balance,
                'is_current_user' => (int) $shift->user_id === (int) $currentUserId,
            ];
        })->values()->toArray();

        return response()->json(['shifts' => $list]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $sess = session()->get('pos');

        if (Auth::user()->can('manage pos') && isset($sess) && !empty($sess) && count($sess) > 0) {




            $user = Auth::user();

            $settings = Utility::settings();

            $customer = Customer::where('name', '=', $request->vc_name)->where('created_by', $user->creatorId())->first();
            $warehouse = warehouse::where('id', '=', $request->warehouse_name)->where('created_by', $user->creatorId())->first();


            $details = [
                'pos_id' => $user->posNumberFormat($this->invoicePosNumber()),
                'customer' => $customer != null ? $customer->toArray() : [],
                'warehouse' => $warehouse != null ? $warehouse->toArray() : [],
                'user' => $user != null ? $user->toArray() : [],
                'date' => date('Y-m-d'),
                'pay' => 'show',
            ];

            $warehouseName = $details['warehouse']['name'] ?? __('Warehouse');
            $userName = $details['user']['name'] ?? '';

            if (!empty($details['customer'])) {
                $warehousedetails = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';
                $details['customer']['billing_state'] = ($details['customer']['billing_state'] ?? '') != '' ? ", " . $details['customer']['billing_state'] : '';
                $details['customer']['shipping_state'] = ($details['customer']['shipping_state'] ?? '') != '' ? ", " . $details['customer']['shipping_state'] : '';

                $customerdetails = '<h6 class="text-dark">' . ucfirst($details['customer']['name'] ?? '') . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_phone'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_city'] ?? '') . ($details['customer']['billing_state'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_country'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_zip'] ?? '') . '</p></h6>';

                $shippdetails = '<h6 class="text-dark"><b>' . ucfirst($details['customer']['name'] ?? '') . '</b>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_phone'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_city'] ?? '') . ($details['customer']['shipping_state'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_country'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_zip'] ?? '') . '</p></h6>';

            } else {
                $customerdetails = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b><h2>';
                $warehousedetails = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';
                $shippdetails = '-';

            }

            $settings['company_telephone'] = ($settings['company_telephone'] ?? '') != '' ? ", " . $settings['company_telephone'] : '';
            $settings['company_state'] = ($settings['company_state'] ?? '') != '' ? ", " . $settings['company_state'] : '';

            $userdetails = '<h6 class="text-dark"><b>' . ucfirst($userName) . ' </b> <h2  class="font-weight-normal">' . '<p class="m-0 font-weight-normal">' . ($settings['company_name'] ?? '') . ($settings['company_telephone'] ?? '') . '</p>' . '<p class="m-0 font-weight-normal">' . ($settings['company_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($settings['company_city'] ?? '') . ($settings['company_state'] ?? '') . '</p>' . '<p class="m-0 font-weight-normal">' . ($settings['company_country'] ?? '') . '</p>' . '<p class="m-0 font-weight-normal">' . ($settings['company_zipcode'] ?? '') . '</p></h2>';

            $details['customer']['details'] = $customerdetails;
            $details['warehouse']['details'] = $warehousedetails;
            //
            $details['customer']['shippdetails'] = $shippdetails;

            $details['user']['details'] = $userdetails;

            $mainsubtotal = 0;
            $sales = [];

            foreach ($sess as $key => $value) {

                $subtotal = $value['price'] * $value['quantity'];
                $tax = ($subtotal * ($value['tax'] ?? 0)) / 100;
                $sales['data'][$key]['name'] = $value['name'] ?? __('Product');
                $sales['data'][$key]['quantity'] = $value['quantity'];
                $sales['data'][$key]['price'] = Auth::user()->priceFormat($value['price']);
                $sales['data'][$key]['tax'] = ($value['tax'] ?? 0) . '%';
                $sales['data'][$key]['product_tax'] = $value['product_tax'] ?? '-';
                $sales['data'][$key]['tax_amount'] = Auth::user()->priceFormat($tax);
                $sales['data'][$key]['subtotal'] = Auth::user()->priceFormat($value['subtotal']);
                $mainsubtotal += $value['subtotal'];
            }

            $discount = !empty($request->discount) ? $request->discount : 0;
            $sales['discount'] = Auth::user()->priceFormat($discount);
            $total = $mainsubtotal - $discount;
            $sales['sub_total'] = Auth::user()->priceFormat($mainsubtotal);
            $sales['total'] = Auth::user()->priceFormat($total);

            return view('pos.show', compact('sales', 'details'));
        } else {
            return response()->json(
                [
                    'error' => __('Add some products to cart!'),
                ],
                '404'
            );
        }
    }

    /**
     * Sync offline POS sales (Phase 5). Accepts batch of sales with cart payload.
     *
     * @param  \Illuminate\Http\Request  $request  Expects JSON: { sales: [ { vc_name, warehouse_name, discount, payment_method, pos_order_type?, pos_table_id?, pos_shift_id?, quotation_id?, cart: {...} }, ... ] }
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync(Request $request)
    {
        if (!Auth::user()->can('manage pos')) {
            return response()->json(['code' => 403, 'error' => __('Permission denied.')], 403);
        }

        $sales = $request->input('sales', []);
        if (!is_array($sales) || empty($sales)) {
            return response()->json(['code' => 400, 'error' => __('No sales to sync.')], 400);
        }

        $synced = 0;
        $errors = [];

        foreach ($sales as $index => $sale) {
            $cart = $sale['cart'] ?? [];
            if (empty($cart)) {
                $errors[] = ['index' => $index, 'error' => __('Items not found!')];
                continue;
            }
            session()->put('pos', $cart);
            $req = new Request(array_merge($sale, [
                'date' => $sale['date'] ?? date('Y-m-d'),
            ]));
            $req->setUserResolver(fn () => Auth::user());
            $prev = request();
            app()->instance('request', $req);
            try {
                $response = $this->processOneSale($req);
                $data = $response->getData(true);
                if (isset($data['code']) && $data['code'] == 200) {
                    $synced++;
                } else {
                    $errors[] = ['index' => $index, 'error' => $data['success'] ?? $data['error'] ?? 'Unknown'];
                }
            } catch (\Throwable $e) {
                $errors[] = ['index' => $index, 'error' => $e->getMessage()];
            }
            session()->forget('pos');
            app()->instance('request', $prev);
        }

        return response()->json([
            'code' => 200,
            'success' => __(':count sale(s) synced successfully.', ['count' => $synced]),
            'synced' => $synced,
            'total' => count($sales),
            'errors' => $errors,
        ]);
    }

    /**
     * Process a single POS sale (used by store and sync).
     *
     * @param  \Illuminate\Http\Request  $request  Must contain vc_name, warehouse_name, discount, payment_method; cart from request or session.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function processOneSale(Request $request)
    {
        $discount = $request->discount ?? 0;
        $user_id = Auth::user()->creatorId();
        $sales = $request->input('cart') ?: session()->get('pos');

        if ($request->quotation_id && (int) $request->quotation_id != 0) {
            $quotation = Quotation::where('id', $request->quotation_id)->first();
            if ($quotation) {
                $quotation->is_converted = 1;
                $quotation->save();
            }
        }

        $customer_id = Customer::customer_id($request->vc_name);
        $warehouse_id = warehouse::warehouse_id($request->warehouse_name);
        $pos_id = $this->invoicePosNumber();

        if (!isset($sales) || empty($sales) || count($sales) == 0) {
            return response()->json(['code' => 404, 'success' => __('Items not found!')], 404);
        }

        $result = DB::table('pos')->where('pos_id', $pos_id)->where('created_by', $user_id)->get();
        if (count($result) > 0) {
            return response()->json(['code' => 200, 'success' => __('Payment is already completed!')]);
        }

        $warehouse = warehouse::find($request->warehouse_name);
        $branch_id = ($warehouse && $warehouse->branch_id) ? $warehouse->branch_id : Branch::where('created_by', $user_id)->value('id');

        $pos = new Pos();
        $pos->pos_id = $pos_id;
        $pos->branch_id = $branch_id;
        $pos->order_type = $request->pos_order_type ?: null;
        $pos->table_id = $request->pos_table_id ?: null;
        $pos->shift_id = $request->pos_shift_id ?: null;
        $pos->customer_id = $customer_id;
        $pos->warehouse_id = $request->warehouse_name;
        $pos->pos_date = date('Y-m-d');
        $pos->payment_method = $request->payment_method;
        $pos->created_by = $user_id;
        $pos->save();

        if ($request->quotation_id && (int) $request->quotation_id != 0) {
            $quotation = Quotation::find($request->quotation_id);
            if ($quotation) {
                $quotation->converted_pos_id = $pos->id;
                $quotation->save();
            }
        }

        foreach ($sales as $key => $value) {
            $product_id = $value['id'];
            $product = ProductService::whereId($product_id)->where('created_by', $user_id)->first();
            $original_quantity = ($product == null) ? 0 : (int) $product->quantity;
            $product_quantity = $original_quantity - $value['quantity'];
            if ($product != null && !empty($product)) {
                ProductService::where('id', $product_id)->update(['quantity' => $product_quantity]);
            }
            $tax_id = ProductService::tax_id($product_id);
            $positems = new PosProduct();
            $positems->pos_id = $pos->id;
            $positems->product_id = $product_id;
            $positems->price = $value['price'];
            $positems->quantity = $value['quantity'];
            $positems->tax = $tax_id;
            $positems->discount = $discount;
            $positems->save();
            Utility::warehouse_quantity('minus', $positems->quantity, $positems->product_id, $request->warehouse_name);
            $type = 'pos';
            $type_id = $pos->id;
            StockReport::where('type', '=', 'pos')->where('type_id', '=', $pos->id)->delete();
            $description = $positems->quantity . '  ' . __(' quantity sold in pos') . ' ' . \Auth::user()->posNumberFormat($pos->pos_id);
            Utility::addProductStock($positems->product_id, $positems->quantity, $type, $description, $type_id);
        }

        $posPayment = new PosPayment();
        $posPayment->pos_id = $pos->id;
        $posPayment->branch_id = $pos->branch_id;
        $posPayment->date = $request->date ?? date('Y-m-d');
        $mainsubtotal = 0;
        foreach (session()->get('pos', $sales) as $key => $value) {
            $mainsubtotal += $value['subtotal'] ?? ($value['price'] * $value['quantity']);
        }
        $posPayment->amount = $mainsubtotal;
        $posPayment->discount = $discount;
        $posPayment->discount_amount = $mainsubtotal - $discount;
        $posPayment->save();

        $pos->load('items');
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
        session()->forget('pos');

        return response()->json([
            'code' => 200,
            'success' => __('Payment completed successfully!'),
            'invoice_id' => $invoice ? $invoice->id : null,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $discount = $request->discount;

        if (Auth::user()->can('manage pos')) {

            if($request->quotation_id != 0)
            {
                $quotation = Quotation::where('id', $request->quotation_id)->first();
                $quotation->is_converted = 1;
                $quotation->save();
            }


            $user_id = Auth::user()->creatorId();
            $customer_id = Customer::customer_id($request->vc_name);
            $warehouse_id = warehouse::warehouse_id($request->warehouse_name);
            $pos_id = $this->invoicePosNumber();
            $sales = $request->input('cart') ?: session()->get('pos');

            if (isset($sales) && !empty($sales) && count($sales) > 0) {
                $result = DB::table('pos')->where('pos_id', $pos_id)->where('created_by', $user_id)->get();
                if (count($result) > 0) {
                    return response()->json(
                        [
                            'code' => 200,
                            'success' => __('Payment is already completed!'),
                        ]
                    );
                } else {
                    $warehouse = warehouse::find($request->warehouse_name);
                    $branch_id = ($warehouse && $warehouse->branch_id) ? $warehouse->branch_id : Branch::where('created_by', $user_id)->value('id');

                    $pos = new Pos();
                    $pos->pos_id = $pos_id;
                    $pos->branch_id = $branch_id;
                    $pos->order_type = $request->pos_order_type ?: null;
                    $pos->table_id = $request->pos_table_id ?: null;
                    $pos->shift_id = $request->pos_shift_id ?: null;
                    $pos->customer_id = $customer_id;
                    $pos->warehouse_id = $request->warehouse_name;
                    $pos->pos_date = date('Y-m-d');
                    $pos->payment_method = $request->payment_method;
                    $pos->created_by = $user_id;
                    $pos->save();

                    if($request->quotation_id != 0)
                    {
                        $quotation->converted_pos_id = $pos->id;
                        $quotation->save();
                    }
                    foreach ($sales as $key => $value) {
                        $product_id = $value['id'];

                        $product = ProductService::whereId($product_id)->where('created_by', $user_id)->first();

                        $original_quantity = ($product == null) ? 0 : (int) $product->quantity;

                        $product_quantity = $original_quantity - $value['quantity'];

                        if ($product != null && !empty($product)) {
                            ProductService::where('id', $product_id)->update(['quantity' => $product_quantity]);
                        }

                        $tax_id = ProductService::tax_id($product_id);

                        $positems = new PosProduct();
                        $positems->pos_id = $pos->id;
                        $positems->product_id = $product_id;
                        $positems->price = $value['price'];
                        $positems->quantity = $value['quantity'];
                        $positems->tax = $tax_id;
                        $positems->discount = $discount;
                        $positems->save();

                        Utility::warehouse_quantity('minus', $positems->quantity, $positems->product_id, $request->warehouse_name);

                        //Product Stock Report
                        $type = 'pos';
                        $type_id = $pos->id;
                        StockReport::where('type', '=', 'pos')->where('type_id', '=', $pos->id)->delete();
                        $description = $positems->quantity . '  ' . __(' quantity sold in pos') . ' ' . \Auth::user()->posNumberFormat($pos->pos_id);
                        Utility::addProductStock($positems->product_id, $positems->quantity, $type, $description, $type_id);

                    }

                    $posPayment = new PosPayment();
                    $posPayment->pos_id = $pos->id;
                    $posPayment->branch_id = $pos->branch_id;
                    $posPayment->date = $request->date;

                    $mainsubtotal = 0;
                    $sales = [];

                    $sess = session()->get('pos');

                    foreach ($sess as $key => $value) {
                        $subtotal = $value['price'] * $value['quantity'];
                        $tax = ($subtotal * $value['tax']) / 100;
                        $sales['data'][$key]['price'] = Auth::user()->priceFormat($value['price']);
                        $sales['data'][$key]['tax'] = $value['tax'] . '%';
                        $sales['data'][$key]['tax_amount'] = Auth::user()->priceFormat($tax);
                        $sales['data'][$key]['subtotal'] = Auth::user()->priceFormat($value['subtotal']);
                        $mainsubtotal += $value['subtotal'];
                    }
                    $amount = $mainsubtotal;
                    $posPayment->amount = $amount;
                    $total = $mainsubtotal - $discount;
                    $posPayment->discount = $discount;
                    $posPayment->discount_amount = $total;
                    $posPayment->save();

                    // Phase 2: Create invoice and accounting entries from POS
                    $pos->load('items');
                    $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);

                    session()->forget('pos');

                    return response()->json(
                        [
                            'code' => 200,
                            'success' => __('Payment completed successfully!'),
                            'invoice_id' => $invoice ? $invoice->id : null,
                        ]
                    );
                }
            } else {
                return response()->json(
                    [
                        'code' => 404,
                        'success' => __('Items not found!'),
                    ]
                );
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show($ids)
    {

        if (\Auth::user()->can('show pos') || \Auth::user()->type == 'company') {
            try {
                $id = Crypt::decrypt($ids);
            } catch (\Throwable $th) {
                return redirect()->back()->with('error', __('Pos Not Found.'));
            }

            $id = Crypt::decrypt($ids);

            $pos = Pos::with(['invoice', 'branch', 'table', 'shift'])->find($id);

            if ($pos->created_by == \Auth::user()->creatorId()) {
                $posPayment = PosPayment::where('pos_id', $pos->id)->first();
                $customer = $pos->customer;
                $iteams = $pos->items;

                return view('pos.view', compact('pos', 'customer', 'iteams', 'posPayment'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function invoicePosNumber()
    {
        if (Auth::user()->can('manage pos')) {
            $latest = Pos::where('created_by', '=', \Auth::user()->creatorId())->orderBy('id', 'desc')->first();

            return $latest ? $latest->pos_id + 1 : 1;
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function report()
    {
        if (\Auth::user()->can('manage pos')) {

            $posPayments = Pos::where('created_by', '=', \Auth::user()->creatorId())->with(['customer', 'warehouse', 'branch'])->get();

            return view('pos.report', compact('posPayments'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    public function barcode()
    {
        if (\Auth::user()->can('manage pos')) {
            $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())->get();
            $barcode = [
                'barcodeType' => Auth::user()->barcodeType(),
                'barcodeFormat' => Auth::user()->barcodeFormat(),
            ];

            return view('pos.barcode', compact('productServices', 'barcode'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    public function setting()
    {
        if (\Auth::user()->can('manage pos')) {
            $settings = Utility::settings();

            return view('pos.setting', compact('settings'));
        } else {
            return redirect()->back()->with('error', 'Permission denied.');
        }

    }

    public function BarcodesettingStore(Request $request)
    {
        $request->validate(
            [
                'barcode_type' => 'required',
                'barcode_format' => 'required',
            ]
        );

        $post['barcode_type'] = $request->barcode_type;
        $post['barcode_format'] = $request->barcode_format;

        foreach ($post as $key => $data) {

            $arr = [
                $data,
                $key,
                \Auth::user()->id,
            ];

            \DB::insert(
                'insert into settings (`value`, `name`,`created_by`) values (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ', $arr
            );
        }
        return redirect()->back()->with('success', 'Barcode setting successfully updated.');

    }

    public function printBarcode()
    {
        if (\Auth::user()->can('manage pos')) {
            $warehouses = warehouse::select('*', \DB::raw("CONCAT(name) AS name"))->where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            return view('pos.print', compact('warehouses'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    public function getproduct(Request $request)
    {
        if ($request->warehouse_id == 0) {
            $productServices = WarehouseProduct::where('product_id', '=', $request->warehouse_id)->where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id')->toArray();
        } else {
            $productServicesId = WarehouseProduct::where('created_by', '=', \Auth::user()->creatorId())->where('warehouse_id', $request->warehouse_id)->get()->pluck('product_id')->toArray();
            $productServices = ProductService::whereIn('id', $productServicesId)->get()->pluck('name', 'id')->toArray();
        }

        return response()->json($productServices);
    }

    /**
     * Get warehouses by branch for POS multi-branch support.
     */
    public function getWarehousesByBranch(Request $request)
    {
        $branchId = $request->branch_id;
        $warehouses = warehouse::where('created_by', \Auth::user()->creatorId());

        if ($branchId) {
            $warehouses->where('branch_id', $branchId);
        }

        $warehouses = $warehouses->get()->pluck('name', 'id')->toArray();

        return response()->json($warehouses);
    }

    public function receipt(Request $request)
    {
        if (!empty($request->product_id)) {
            $productServices = ProductService::whereIn('id', $request->product_id)->get();
            $quantity = $request->quantity;
            $barcode = [
                'barcodeType' => Auth::user()->barcodeType() == '' ? 'code128' : Auth::user()->barcodeType(),
                'barcodeFormat' => Auth::user()->barcodeFormat() == '' ? 'css' : Auth::user()->barcodeFormat(),
            ];
        } else {
            return redirect()->back()->with('error', 'Product is required.');

        }

        return view('pos.receipt', compact('productServices', 'barcode', 'quantity'));

    }

    public function cartdiscount(Request $request)
    {

        if ($request->discount) {
            $sess = session()->get('pos');
            $subtotal = !empty($sess) ? array_sum(array_column($sess, 'subtotal')) : 0;
            $discount = $request->discount;
            $total = $subtotal - $discount;
            $total = \Auth::user()->priceFormat($total);

        } else {
            $sess = session()->get('pos');
            $subtotal = !empty($sess) ? array_sum(array_column($sess, 'subtotal')) : 0;
            $discount = 0;
            $total = $subtotal - $discount;
            $total = \Auth::user()->priceFormat($total);
        }

        return response()->json(['total' => $total], '200');

    }

    public function pos($pos_id)
    {
        $settings = Utility::settings();
        try{
            $posId = Crypt::decrypt($pos_id);
        } catch (\Exception $e){
            return redirect()->back()->with('error', __('Something went wrong.'));
        }
        $pos = Pos::with(['branch', 'table', 'shift'])->where('id', $posId)->first();

        $posPayment = PosPayment::where('pos_id', $pos->id)->first();

        $data = DB::table('settings');
        $data = $data->where('created_by', '=', $pos->created_by);
        $data1 = $data->get();

        foreach ($data1 as $row) {
            $settings[$row->name] = $row->value;
        }

        $customer = $pos->customer;

        $totalTaxPrice = 0;
        $totalQuantity = 0;
        $totalRate = 0;
        $totalDiscount = 0;
        $taxesData = [];
        $items = [];

        foreach ($pos->items as $product) {

            $item = new \stdClass();
            $item->name = !empty($product->product()) ? $product->product()->name : '';
            $item->quantity = $product->quantity;
            $item->tax = $product->tax;
            $item->discount = $product->discount;
            $item->price = $product->price;
            $item->description = $product->description;
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

        $pos->itemData = $items;
        $pos->totalTaxPrice = $totalTaxPrice;
        $pos->totalQuantity = $totalQuantity;
        $pos->totalRate = $totalRate;
        $pos->totalDiscount = $totalDiscount;
        $pos->taxesData = $taxesData;

        $logo = asset(Storage::url('uploads/logo/'));
        $company_logo = Utility::getValByName('company_logo_dark');
        $pos_logo = Utility::getValByName('pos_logo');
        if (isset($pos_logo) && !empty($pos_logo)) {
            $img = Utility::get_file('pos_logo/') . $pos_logo;
        } else {
            $img = asset($logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png'));
        }

        if ($pos) {
            $color = '#' . $settings['pos_color'];
            $font_color = Utility::getFontColor($color);

            return view('pos.templates.' . $settings['pos_template'], compact('pos', 'posPayment', 'color', 'settings', 'customer', 'img', 'font_color'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function previewPos($template, $color)
    {

        $objUser = \Auth::user();
        $settings = Utility::settings();

        $pos = new Pos();
        $posPayment = new posPayment();
        $posPayment->amount = 360;
        $posPayment->discount = 100;

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
                $itemTax['rate'] = '10 %';
                $itemTax['price'] = '$10';
                $itemTaxes[] = $itemTax;
                if (array_key_exists('Tax ' . $k, $taxesData)) {
                    $taxesData['Tax ' . $k] = $taxesData['Tax 1'] + $taxPrice;
                } else {
                    $taxesData['Tax ' . $k] = $taxPrice;
                }
            }
            $item->itemTax = $itemTaxes;
            $items[] = $item;
        }

        $pos->pos_id = 1;

        $pos->issue_date = date('Y-m-d H:i:s');
        $pos->itemData = $items;

        $pos->totalTaxPrice = 60;
        $pos->totalQuantity = 3;
        $pos->totalRate = 300;
        $pos->totalDiscount = 10;
        $pos->taxesData = $taxesData;
        $pos->created_by = $objUser->creatorId();

        $preview = 1;
        $color = '#' . $color;
        $font_color = Utility::getFontColor($color);

        $logo = asset(Storage::url('uploads/logo/'));

        $company_logo = Utility::getValByName('company_logo_dark');
        $settings_data = \App\Models\Utility::settingsById($pos->created_by);
        $pos_logo = $settings_data['pos_logo'];

        if (isset($pos_logo) && !empty($pos_logo)) {
            $img = Utility::get_file('pos_logo/') . $pos_logo;
        } else {
            $img = asset($logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png'));
        }

        return view('pos.templates.' . $template, compact('pos', 'preview', 'color', 'img', 'settings', 'customer', 'font_color', 'posPayment'));
    }

    public function savePosTemplateSettings(Request $request)
    {

        $post = $request->all();
        unset($post['_token']);

        if (isset($post['pos_template']) && (!isset($post['pos_color']) || empty($post['pos_color']))) {
            $post['pos_color'] = "ffffff";
        }

        if ($request->pos_logo) {
            $dir = 'pos_logo/';
            $pos_logo = \Auth::user()->id . '_pos_logo.png';
            $validation = [
                'mimes:' . 'png',
                'max:' . '20480',
            ];
            $path = Utility::upload_file($request, 'pos_logo', $pos_logo, $dir, $validation);
            if ($path['flag'] == 0) {
                return redirect()->back()->with('error', __($path['msg']));
            }
            $post['pos_logo'] = $pos_logo;
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

        return redirect()->back()->with('success', __('POS Setting updated successfully'));
    }

    //for thermal print
    public function printView(Request $request)
    {

        $sess = session()->get('pos');

        $user = Auth::user();
        $settings = Utility::settings();

        $customer = Customer::where('name', '=', $request->vc_name)->where('created_by', $user->creatorId())->first();
        $warehouse = warehouse::where('id', '=', $request->warehouse_name)->where('created_by', $user->creatorId())->first();

        $details = [
            'pos_id' => $user->posNumberFormat($this->invoicePosNumber()),
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

        return view('pos.printview', compact('details', 'sales', 'customer', 'productServices', 'barcode'));

    }
    
    public function add_opening_balance()
    {
        return view('pos.add_opening_balance');
    }
    
    public function save_opening_balance(Request $request)
    {
        $balance = \App\Models\PosBalance::firstOrNew([
            'user_id' => \Auth::user()->creatorId(),
            'created_at' => now()->startOfDay(), // Not effective, will override below
        ]);
    
        $existing = \App\Models\PosBalance::where('user_id', \Auth::user()->creatorId())
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->first();
    
        if ($existing) {
            $existing->opening_balance = $request->amount;
            $existing->save();
        } else {
            $new = new \App\Models\PosBalance();
            $new->user_id = \Auth::user()->creatorId();
            $new->opening_balance = $request->amount;
            $new->save();
        }
    
        return redirect()->back()->with('success', __('Opening Balance added successfully.'));
    }

    
    public function add_closing_balance()
    {
        return view('pos.add_closing_balance');
    }
    
    public function save_closing_balance(Request $request)
    {
        $existing = \App\Models\PosBalance::where('user_id', \Auth::user()->creatorId())
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->first();
    
        if ($existing) {
            $existing->closing_balance = $request->amount;
            $existing->save();
        } else {
            $new = new \App\Models\PosBalance();
            $new->user_id = \Auth::user()->creatorId();
            $new->closing_balance = $request->amount;
            $new->save();
        }
    
        return redirect()->back()->with('success', __('Closing Balance added successfully.'));
    }

    
    public function balances()
    {
        $pos_balances = PosBalance::where('user_id', \Auth::user()->creatorId())->orderBy('id', 'desc')->get();
        return view('pos.balances', compact('pos_balances'));
    }

}
