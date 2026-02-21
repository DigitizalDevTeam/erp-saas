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
use App\Models\PosAuditLog;
use App\Services\PosAuditLogService;
use App\Services\PosRecipeDeductionService;
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
        if (Auth::user()->canManagePos()) {
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
            $customersForPos = Customer::where('created_by', \Auth::user()->creatorId())->get(['id', 'name', 'email', 'contact']);
            $customersForPos = collect([(object)['id' => 0, 'name' => 'Walk-in-customer', 'email' => 'walkin@default.com', 'contact' => '0000000000']])->merge($customersForPos);
            // Retail POS: only retail branches (pos_type = retail or null)
            $retailBranchIds = Branch::where('created_by', $creatorId)->where('is_active', true)
                ->where(function ($q) {
                    $q->where('pos_type', Branch::POS_TYPE_RETAIL)->orWhereNull('pos_type');
                })
                ->pluck('id')->toArray();
            $posBranchLocked = Auth::user()->hasPosBranchRestriction();
            if ($posBranchLocked && Auth::user()->pos_branch_id) {
                $retailBranchIds = array_intersect($retailBranchIds, [Auth::user()->pos_branch_id]);
            }
            $branches = Branch::whereIn('id', $retailBranchIds)->get()->pluck('name', 'id');
            $warehouses = warehouse::with('branch')->where('created_by', \Auth::user()->creatorId())
                ->where(function ($q) use ($retailBranchIds) {
                    if (!empty($retailBranchIds)) {
                        $q->whereIn('branch_id', $retailBranchIds);
                    }
                    $q->orWhereNull('branch_id');
                })->get()->pluck('name', 'id');
            $restaurantBranchIds = [];
            $hasRetailBranches = !empty($retailBranchIds);
            $hasRestaurantBranches = false;
            $posMode = 'retail';
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
            $canChangeShift = $user->can('pos restaurant full') || $user->can('pos restaurant manager') || $user->can('manage pos');
            $userOpenShifts = \App\Models\PosShift::where('user_id', $user->id)
                ->where('status', \App\Models\PosShift::STATUS_OPEN)
                ->where('created_by', $creatorId)
                ->orderBy('opened_at', 'desc')
                ->get();
            $userOpenShiftByBranch = $userOpenShifts->pluck('id', 'branch_id')->toArray();
            $userOpenShift = $userOpenShifts->first();
            $defaultBranchIdFromShift = $userOpenShift ? $userOpenShift->branch_id : null;
            if ($defaultBranchIdFromShift && $id == 0) {
                $wh = warehouse::where('branch_id', $defaultBranchIdFromShift)->where('created_by', $creatorId)->value('id');
                if ($wh) {
                    $warehouseId = $wh;
                }
            }
            return view('pos.index', compact('customers', 'customersForPos', 'branches', 'warehouses', 'details', 'customer', 'warehouseId', 'id', 'restaurantBranchIds', 'hasRetailBranches', 'hasRestaurantBranches', 'posMode', 'userOpenShift', 'canChangeShift', 'userOpenShiftByBranch', 'defaultBranchIdFromShift', 'posBranchLocked'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Get restaurant layout data: areas with tables for a branch.
     */
    public function getRestaurantLayout(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
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
        $tablesQuery = PosTable::where('created_by', Auth::user()->creatorId())
            ->where('branch_id', $branchId)
            ->with('area');

        $posActorId = session('pos_pin_user_id', Auth::id());
        $posActor = User::find($posActorId);
        // Waiter sees only tables assigned to their current shift for this branch
        if ($posActor && $posActor->can('pos restaurant waiter') && !$posActor->can('pos restaurant full') && !$posActor->can('pos restaurant manager')) {
            $openShift = \App\Models\PosShift::where('branch_id', $branchId)
                ->where('user_id', $posActorId)
                ->where('status', \App\Models\PosShift::STATUS_OPEN)
                ->where('created_by', Auth::user()->creatorId())
                ->orderBy('opened_at', 'desc')
                ->first();
            if ($openShift) {
                $assignedTableIds = \App\Models\PosTableAssignment::where('shift_id', $openShift->id)
                    ->whereNull('unassigned_at')
                    ->pluck('table_id');
                $tablesQuery->whereIn('id', $assignedTableIds);
            } else {
                $tablesQuery->whereRaw('0 = 1'); // no shift = no tables
            }
        }

        $tables = $tablesQuery->orderBy('area_id')->orderBy('name')->get();
        $result = $areas->map(function ($area) use ($tables) {
            return [
                'id' => $area->id,
                'name' => $area->name,
                'tables' => $tables->where('area_id', $area->id)->values()->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'status' => $t->status,
                    'capacity' => $t->capacity,
                    'current_guests' => $t->current_guests,
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
                    'current_guests' => $t->current_guests,
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
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $branchId = $request->has('branch_id') ? (int) $request->branch_id : null;
        if (!$branchId) {
            return response()->json(['shift' => null]);
        }
        $posActorId = session('pos_pin_user_id', Auth::id());
        $shift = \App\Models\PosShift::where('branch_id', $branchId)
            ->where('user_id', $posActorId)
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
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $branchId = $request->has('branch_id') ? (int) $request->branch_id : null;
        if (!$branchId) {
            return response()->json(['shifts' => []]);
        }
        $currentUserId = session('pos_pin_user_id', Auth::id());
        $shifts = \App\Models\PosShift::where('branch_id', $branchId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', Auth::user()->creatorId())
            ->with('user:id,name')
            ->orderBy('opened_at', 'desc')
            ->get();

        $shiftIds = $shifts->pluck('id');
        $assignments = \App\Models\PosTableAssignment::whereIn('shift_id', $shiftIds)
            ->whereNull('unassigned_at')
            ->with('table:id,name')
            ->get()
            ->groupBy('shift_id');

        $list = $shifts->map(function ($shift) use ($currentUserId, $assignments) {
            $tables = ($assignments->get($shift->id) ?? collect())->map(function ($a) {
                return [
                    'id' => $a->table_id,
                    'name' => $a->table ? $a->table->name : ('Table ' . $a->table_id),
                ];
            })->values()->toArray();
            return [
                'id' => $shift->id,
                'user_id' => $shift->user_id,
                'user_name' => $shift->user ? $shift->user->name : __('Unknown'),
                'opened_at' => $shift->opened_at ? $shift->opened_at->format('H:i') : '',
                'opening_balance' => $shift->opening_balance,
                'is_current_user' => (int) $shift->user_id === (int) $currentUserId,
                'assigned_tables' => $tables,
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

        // Restaurant: when paying a table order, load cart from existing Pos if session cart is empty
        $tableId = (int) $request->get('pos_table_id');
        $shiftId = (int) $request->get('pos_shift_id');
        if (Auth::user()->canManagePos() && $tableId && $shiftId && (empty($sess) || !is_array($sess) || count($sess) === 0)) {
            $creatorId = Auth::user()->creatorId();
            $pos = Pos::where('table_id', $tableId)
                ->where('shift_id', $shiftId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->with(['items.product'])
                ->first();
            if ($pos && $pos->items->isNotEmpty()) {
                $sess = [];
                foreach ($pos->items as $line) {
                    $product = $line->product;
                    $price = (float) $line->price;
                    $qty = (int) $line->quantity;
                    $taxRate = 0;
                    if ($product && !empty($product->tax_id)) {
                        $taxIds = array_filter(array_map('trim', explode(',', $product->tax_id)));
                        $taxModels = \App\Models\Tax::whereIn('id', $taxIds)->get();
                        foreach ($taxModels as $t) {
                            $taxRate += $t->rate ?? 0;
                        }
                    }
                    $subtotal = $price * $qty;
                    $taxAmount = ($subtotal * $taxRate) / 100;
                    $sess[$line->product_id] = [
                        'id' => $line->product_id,
                        'name' => $product ? $product->name : __('Product'),
                        'quantity' => $qty,
                        'price' => $price,
                        'tax' => $taxRate,
                        'subtotal' => $subtotal + $taxAmount,
                        'product_tax' => $taxRate ? $taxRate . '%' : '-',
                    ];
                }
                // Merge any unsent session cart for this table
                $cartKey = 'pos_restaurant_cart';
                $tableKey = $tableId . '_' . $shiftId;
                $allCarts = $request->session()->get($cartKey, []);
                if (!empty($allCarts[$tableKey])) {
                    foreach ($allCarts[$tableKey] as $pid => $v) {
                        $pid = is_numeric($pid) ? (int) $pid : $pid;
                        if (isset($sess[$pid])) {
                            $sess[$pid]['quantity'] += (int) ($v['quantity'] ?? 0);
                            $sub = ($sess[$pid]['price'] * $sess[$pid]['quantity']) * (1 + ($sess[$pid]['tax'] ?? 0) / 100);
                            $sess[$pid]['subtotal'] = $sub;
                        } else {
                            $sess[$pid] = [
                                'id' => $pid,
                                'name' => $v['name'] ?? __('Product'),
                                'quantity' => (int) ($v['quantity'] ?? 1),
                                'price' => (float) ($v['price'] ?? 0),
                                'tax' => (float) ($v['tax'] ?? 0),
                                'subtotal' => (float) ($v['subtotal'] ?? 0),
                                'product_tax' => $v['product_tax'] ?? '-',
                            ];
                        }
                    }
                }
            }
        }

        if (Auth::user()->canManagePos() && isset($sess) && !empty($sess) && count($sess) > 0) {




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
            $taxTotal = 0;
            $sales = [];

            foreach ($sess as $key => $value) {
                $subtotal = $value['price'] * $value['quantity'];
                $tax = ($subtotal * ($value['tax'] ?? 0)) / 100;
                $taxTotal += $tax;
                $sales['data'][$key]['name'] = $value['name'] ?? __('Product');
                $sales['data'][$key]['quantity'] = $value['quantity'];
                $sales['data'][$key]['price'] = Auth::user()->priceFormat($value['price']);
                $sales['data'][$key]['tax'] = ($value['tax'] ?? 0) . '%';
                $sales['data'][$key]['product_tax'] = $value['product_tax'] ?? '-';
                $sales['data'][$key]['tax_amount'] = Auth::user()->priceFormat($tax);
                $sales['data'][$key]['subtotal'] = Auth::user()->priceFormat($value['subtotal']);
                $mainsubtotal += $value['subtotal'];
            }

            $discount = !empty($request->discount) ? (float) $request->discount : 0;
            $total = $mainsubtotal - $discount;
            $sales['discount'] = Auth::user()->priceFormat($discount);
            $sales['sub_total'] = Auth::user()->priceFormat($mainsubtotal);
            $sales['total'] = Auth::user()->priceFormat($total);

            $orderSummary = [
                'items_count' => count($sess),
                'subtotal' => Auth::user()->priceFormat($mainsubtotal),
                'tax' => Auth::user()->priceFormat($taxTotal),
                'discount' => Auth::user()->priceFormat($discount),
                'total' => Auth::user()->priceFormat($total),
                'total_raw' => round($total, 2),
            ];

            return view('pos.show', compact('sales', 'details', 'orderSummary'));
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
        if (!Auth::user()->canManagePos()) {
            return response()->json(['code' => 403, 'error' => __('Permission denied.')], 403);
        }

        $sales = $request->input('sales', []);
        if (!is_array($sales) || empty($sales)) {
            return response()->json(['code' => 400, 'error' => __('No sales to sync.')], 400);
        }

        $synced = 0;
        $errors = [];

        $creatorId = Auth::user()->creatorId();
        foreach ($sales as $index => $sale) {
            $cart = $sale['cart'] ?? [];
            if (empty($cart)) {
                $errors[] = ['index' => $index, 'error' => __('Items not found!')];
                continue;
            }
            // Phase 8: Idempotent sync - skip if client_order_id already synced
            $clientOrderId = $sale['client_order_id'] ?? null;
            if ($clientOrderId !== null && $clientOrderId !== '') {
                if (Pos::where('created_by', $creatorId)->where('client_order_id', $clientOrderId)->exists()) {
                    $synced++;
                    continue;
                }
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
     * Phase 8: Return which client_order_ids have already been synced (for offline clients to reconcile).
     */
    public function syncStatus(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['code' => 403, 'error' => __('Permission denied.')], 403);
        }
        $ids = $request->input('client_order_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_filter(array_unique($ids));
        $creatorId = Auth::user()->creatorId();
        $synced = Pos::where('created_by', $creatorId)
            ->whereIn('client_order_id', $ids)
            ->whereNotNull('client_order_id')
            ->pluck('client_order_id')
            ->toArray();
        return response()->json([
            'code' => 200,
            'synced' => $synced,
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
        $pos_id = $this->invoicePosNumber();

        if (!isset($sales) || empty($sales) || count($sales) == 0) {
            return response()->json(['code' => 404, 'success' => __('Items not found!')], 404);
        }

        // Phase 4: Discount above X% requires manager password
        $discountAmount = (float) $discount;
        if ($discountAmount > 0) {
            $subtotal = 0;
            foreach ($sales as $v) {
                $subtotal += ($v['price'] ?? 0) * ($v['quantity'] ?? 0);
            }
            if ($subtotal > 0) {
                $discountPercent = ($discountAmount / $subtotal) * 100;
                $maxPercent = (float) (Utility::getValByName('pos_max_discount_percent') ?: 10);
                if ($discountPercent > $maxPercent && !PosAuditLogService::requireManagerPassword($request)) {
                    return response()->json(['code' => 403, 'error' => __('Manager password required for discount above :max%.', ['max' => $maxPercent])], 403);
                }
                if ($discountPercent > $maxPercent) {
                    PosAuditLogService::log(PosAuditLog::ACTION_DISCOUNT, null, null, __('Discount applied with manager approval'), ['percent' => round($discountPercent, 2), 'amount' => $discountAmount]);
                }
            }
        }

        // Resolve warehouse_id: required, cannot be null. Use request value if valid, else first warehouse for creator.
        $warehouse_id = warehouse::warehouse_id($request->warehouse_name);
        if (empty($request->warehouse_name) || $warehouse_id <= 0) {
            $defaultWarehouse = warehouse::where('created_by', $user_id)->first();
            if (!$defaultWarehouse) {
                return response()->json(['code' => 404, 'success' => __('Please create or select a warehouse.')], 404);
            }
            $warehouse_id = $defaultWarehouse->id;
        }

        // Phase 8: Idempotent - if client_order_id already exists, return success
        $clientOrderId = $request->client_order_id ?? null;
        if ($clientOrderId !== null && $clientOrderId !== '') {
            if (Pos::where('created_by', $user_id)->where('client_order_id', $clientOrderId)->exists()) {
                return response()->json(['code' => 200, 'success' => __('Payment is already completed!')]);
            }
        }

        $result = DB::table('pos')->where('pos_id', $pos_id)->where('created_by', $user_id)->get();
        if (count($result) > 0) {
            return response()->json(['code' => 200, 'success' => __('Payment is already completed!')]);
        }

        $warehouse = warehouse::find($warehouse_id);
        $branch_id = ($warehouse && $warehouse->branch_id) ? $warehouse->branch_id : Branch::where('created_by', $user_id)->value('id');

        $pos = new Pos();
        $pos->pos_id = $pos_id;
        $pos->branch_id = $branch_id;
        $pos->order_type = $request->pos_order_type ?: null;
        $pos->table_id = $request->pos_table_id ?: null;
        $pos->shift_id = $request->pos_shift_id ?: null;
        $pos->customer_id = $customer_id;
        $pos->warehouse_id = $warehouse_id;
        $pos->pos_date = date('Y-m-d');
        $pos->payment_method = $request->payment_method;
        $pos->created_by = $user_id;
        $pos->client_order_id = $clientOrderId;
        $pos->save();

        if ($request->quotation_id && (int) $request->quotation_id != 0) {
            $quotation = Quotation::find($request->quotation_id);
            if ($quotation) {
                $quotation->converted_pos_id = $pos->id;
                $quotation->save();
            }
        }

        // Phase 5: Clear stock reports for this pos once (then we add per ingredient/product)
        StockReport::where('type', 'pos')->where('type_id', $pos->id)->delete();
        foreach ($sales as $key => $value) {
            $product_id = $value['id'];
            $product = ProductService::whereId($product_id)->where('created_by', $user_id)->first();
            $tax_id = $product ? ProductService::tax_id($product_id) : '0';
            $positems = new PosProduct();
            $positems->pos_id = $pos->id;
            $positems->product_id = $product_id;
            $positems->price = $value['price'];
            $positems->quantity = $value['quantity'];
            $positems->tax = $tax_id;
            $positems->discount = $discount;
            $positems->save();
            PosRecipeDeductionService::deductForSale(
                (int) $product_id,
                (float) $value['quantity'],
                $warehouse_id,
                $user_id,
                \Auth::user()->posNumberFormat($pos->pos_id),
                (int) $pos->id
            );
        }

        $mainsubtotal = 0;
        foreach (session()->get('pos', $sales) as $key => $value) {
            $mainsubtotal += $value['subtotal'] ?? ($value['price'] * $value['quantity']);
        }
        $totalPaid = $mainsubtotal - $discount;
        $payments = $request->input('payments'); // optional: [{ payment_method, amount, credit_terms?, customer_id? }, ...]
        if (is_array($payments) && count($payments) > 0) {
            foreach ($payments as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if ($amt <= 0) {
                    continue;
                }
                $posPayment = new PosPayment();
                $posPayment->pos_id = $pos->id;
                $posPayment->branch_id = $pos->branch_id;
                $posPayment->date = $request->date ?? date('Y-m-d');
                $allowedMethods = [PosPayment::METHOD_CASH, PosPayment::METHOD_CARD, PosPayment::METHOD_CREDIT, PosPayment::METHOD_MPESA, PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'];
$posPayment->payment_method = in_array($p['payment_method'] ?? '', $allowedMethods, true) ? $p['payment_method'] : PosPayment::METHOD_CASH;
                $posPayment->credit_terms = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['credit_terms'] ?? null) : null;
                $posPayment->customer_id = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['customer_id'] ?? null) : null;
                $posPayment->amount = $amt;
                $posPayment->discount = 0;
                $posPayment->discount_amount = $amt;
                $posPayment->created_by = $user_id;
                $posPayment->save();
            }
        } else {
            $paidAmount = (float) ($request->amount ?? $totalPaid);
            if ($paidAmount <= 0) {
                $paidAmount = $totalPaid;
            }
            $changeAmount = (float) ($request->change_amount ?? 0);
            $posPayment = new PosPayment();
            $posPayment->pos_id = $pos->id;
            $posPayment->branch_id = $pos->branch_id;
            $posPayment->date = $request->date ?? date('Y-m-d');
            $posPayment->payment_method = $request->payment_method ?? PosPayment::METHOD_CASH;
            $posPayment->credit_terms = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->credit_terms ?? null) : null;
            $posPayment->customer_id = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->customer_id ?: $customer_id) : null;
            $posPayment->transaction_id = $request->transaction_id ?: null;
            $posPayment->amount = $paidAmount;
            $posPayment->change_amount = $changeAmount;
            $posPayment->discount = $discount;
            $posPayment->discount_amount = $totalPaid;
            $posPayment->notes = $request->notes ?: null;
            $posPayment->created_by = $user_id;
            $posPayment->save();
        }

        // Phase 3: Close table after payment (restaurant flow)
        if ($pos->table_id) {
            PosTable::where('id', $pos->table_id)->where('created_by', $user_id)->update([
                'status' => PosTable::STATUS_AVAILABLE,
                'current_guests' => 0,
            ]);
        }

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

        if (Auth::user()->canManagePos()) {

            if($request->quotation_id != 0)
            {
                $quotation = Quotation::where('id', $request->quotation_id)->first();
                $quotation->is_converted = 1;
                $quotation->save();
            }


            $user_id = Auth::user()->creatorId();
            $customer_id = Customer::customer_id($request->vc_name);
            $pos_id = $this->invoicePosNumber();
            $sales = $request->input('cart') ?: session()->get('pos');

            // Restaurant: pay existing table order from DB when session cart is empty
            $tableId = (int) $request->get('pos_table_id');
            $shiftId = (int) $request->get('pos_shift_id');
            if (($tableId && $shiftId) && (empty($sales) || !is_array($sales) || count($sales) === 0)) {
                $existingPos = Pos::where('table_id', $tableId)
                    ->where('shift_id', $shiftId)
                    ->where('created_by', $user_id)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->with(['items.product'])
                    ->first();
                if ($existingPos && $existingPos->items->isNotEmpty()) {
                    $sales = [];
                    foreach ($existingPos->items as $line) {
                        $product = $line->product;
                        $price = (float) $line->price;
                        $qty = (int) $line->quantity;
                        $taxRate = 0;
                        if ($product && !empty($product->tax_id)) {
                            $taxIds = array_filter(array_map('trim', explode(',', $product->tax_id)));
                            $taxModels = \App\Models\Tax::whereIn('id', $taxIds)->get();
                            foreach ($taxModels as $t) {
                                $taxRate += $t->rate ?? 0;
                            }
                        }
                        $subtotal = $price * $qty * (1 + $taxRate / 100);
                        $sales[$line->product_id] = [
                            'id' => $line->product_id,
                            'name' => $product ? $product->name : __('Product'),
                            'quantity' => $qty,
                            'price' => $price,
                            'tax' => $taxRate,
                            'subtotal' => $subtotal,
                            'product_tax' => $taxRate ? $taxRate . '%' : '-',
                        ];
                    }
                }
            }

            if (isset($sales) && !empty($sales) && count($sales) > 0) {
                $resolved_warehouse_id = warehouse::warehouse_id($request->warehouse_name);
                if (empty($request->warehouse_name) || $resolved_warehouse_id <= 0) {
                    $defaultWarehouse = warehouse::where('created_by', $user_id)->first();
                    $resolved_warehouse_id = $defaultWarehouse ? $defaultWarehouse->id : null;
                }
                if (empty($resolved_warehouse_id)) {
                    return response()->json(['code' => 404, 'success' => __('Please create or select a warehouse.')], 404);
                }
                $warehouse_id = $resolved_warehouse_id;

                // Restaurant: use existing Pos for this table+shift if we just loaded $sales from it
                $useExistingPos = false;
                if ($tableId && $shiftId) {
                    $existingPos = Pos::where('table_id', $tableId)
                        ->where('shift_id', $shiftId)
                        ->where('created_by', $user_id)
                        ->whereNull('cancelled_at')
                        ->whereDoesntHave('posPayments')
                        ->with('items')
                        ->first();
                    if ($existingPos && $existingPos->items->isNotEmpty()) {
                        $useExistingPos = true;
                    }
                }

                $result = DB::table('pos')->where('pos_id', $pos_id)->where('created_by', $user_id)->get();
                if (count($result) > 0 && !$useExistingPos) {
                    return response()->json(
                        [
                            'code' => 200,
                            'success' => __('Payment is already completed!'),
                        ]
                    );
                }
                if ($useExistingPos) {
                    $pos = $existingPos;
                    $pos->customer_id = $customer_id;
                    $pos->payment_method = $request->payment_method ?? $pos->payment_method;
                    $pos->save();
                    foreach ($pos->items as $line) {
                        PosRecipeDeductionService::deductForSale(
                            (int) $line->product_id,
                            (float) $line->quantity,
                            (int) $pos->warehouse_id,
                            $user_id,
                            \Auth::user()->posNumberFormat($pos->pos_id),
                            (int) $pos->id
                        );
                    }
                    $mainsubtotal = 0;
                    $sessForTotal = $sales;
                    foreach ($sessForTotal as $key => $value) {
                        $mainsubtotal += $value['subtotal'] ?? (($value['price'] ?? 0) * ($value['quantity'] ?? 0));
                    }
                    $total = $mainsubtotal - (float) $discount;
                    $payments = $request->input('payments');
                    if (is_array($payments) && count($payments) > 0) {
                        foreach ($payments as $p) {
                            $amt = (float) ($p['amount'] ?? 0);
                            if ($amt <= 0) continue;
                            $posPayment = new PosPayment();
                            $posPayment->pos_id = $pos->id;
                            $posPayment->branch_id = $pos->branch_id;
                            $posPayment->date = $request->date ?? date('Y-m-d');
                            $posPayment->payment_method = !empty($p['payment_method']) ? $p['payment_method'] : PosPayment::METHOD_CASH;
                            $posPayment->credit_terms = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['credit_terms'] ?? null) : null;
                            $posPayment->customer_id = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['customer_id'] ?? null) : null;
                            $posPayment->amount = $amt;
                            $posPayment->discount = 0;
                            $posPayment->discount_amount = $amt;
                            $posPayment->created_by = $user_id;
                            $posPayment->save();
                        }
                    } else {
                        $paidAmount = (float) ($request->amount ?? $total);
                        if ($paidAmount <= 0) $paidAmount = $total;
                        $changeAmount = (float) ($request->change_amount ?? 0);
                        $posPayment = new PosPayment();
                        $posPayment->pos_id = $pos->id;
                        $posPayment->branch_id = $pos->branch_id;
                        $posPayment->date = $request->date ?? date('Y-m-d');
                        $posPayment->payment_method = $request->payment_method ?? PosPayment::METHOD_CASH;
                        $posPayment->credit_terms = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->credit_terms ?? null) : null;
                        $posPayment->customer_id = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->customer_id ?: $customer_id) : null;
                        $posPayment->transaction_id = $request->transaction_id ?: null;
                        $posPayment->amount = $paidAmount;
                        $posPayment->change_amount = $changeAmount;
                        $posPayment->discount = $discount;
                        $posPayment->discount_amount = $total;
                        $posPayment->notes = $request->notes ?: null;
                        $posPayment->created_by = $user_id;
                        $posPayment->save();
                    }
                    $pos->load('items');
                    $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
                    session()->forget('pos');
                    $cartKey = 'pos_restaurant_cart';
                    $tableKey = $tableId . '_' . $shiftId;
                    $allCarts = session()->get($cartKey, []);
                    if (isset($allCarts[$tableKey])) {
                        unset($allCarts[$tableKey]);
                        session()->put($cartKey, $allCarts);
                    }
                    // Table becomes available after payment so next click asks for guests
                    if ($pos->table_id) {
                        PosTable::where('id', $pos->table_id)->where('created_by', $user_id)->update([
                            'status' => PosTable::STATUS_AVAILABLE,
                            'current_guests' => null,
                        ]);
                    }
                    return response()->json([
                        'code' => 200,
                        'success' => __('Payment completed successfully!'),
                        'invoice_id' => $invoice ? $invoice->id : null,
                        'pos_id' => $pos->id,
                    ]);
                }

                $warehouse = warehouse::find($warehouse_id);
                $branch_id = ($warehouse && $warehouse->branch_id) ? $warehouse->branch_id : Branch::where('created_by', $user_id)->value('id');

                $pos = new Pos();
                    $pos->pos_id = $pos_id;
                    $pos->branch_id = $branch_id;
                    $pos->order_type = $request->pos_order_type ?: null;
                    $pos->table_id = $request->pos_table_id ?: null;
                    $pos->shift_id = $request->pos_shift_id ?: null;
                    $pos->customer_id = $customer_id;
                    $pos->warehouse_id = $warehouse_id;
                    $pos->pos_date = date('Y-m-d');
                    $pos->payment_method = $request->payment_method;
                    $pos->created_by = $user_id;
                    $pos->save();

                    if($request->quotation_id != 0)
                    {
                        $quotation->converted_pos_id = $pos->id;
                        $quotation->save();
                    }
                    StockReport::where('type', 'pos')->where('type_id', $pos->id)->delete();
                    foreach ($sales as $key => $value) {
                        $product_id = $value['id'];
                        $product = ProductService::whereId($product_id)->where('created_by', $user_id)->first();
                        $tax_id = $product ? ProductService::tax_id($product_id) : '0';
                        $positems = new PosProduct();
                        $positems->pos_id = $pos->id;
                        $positems->product_id = $product_id;
                        $positems->price = $value['price'];
                        $positems->quantity = $value['quantity'];
                        $positems->tax = $tax_id;
                        $positems->discount = $discount;
                        $positems->save();
                        PosRecipeDeductionService::deductForSale(
                            (int) $product_id,
                            (float) $value['quantity'],
                            $warehouse_id,
                            $user_id,
                            \Auth::user()->posNumberFormat($pos->pos_id),
                            (int) $pos->id
                        );
                    }

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
                    $total = $mainsubtotal - $discount;
                    $payments = $request->input('payments');
                    if (is_array($payments) && count($payments) > 0) {
                        foreach ($payments as $p) {
                            $amt = (float) ($p['amount'] ?? 0);
                            if ($amt <= 0) {
                                continue;
                            }
                            $posPayment = new PosPayment();
                            $posPayment->pos_id = $pos->id;
                            $posPayment->branch_id = $pos->branch_id;
                            $posPayment->date = $request->date;
                            $posPayment->payment_method = !empty($p['payment_method']) ? $p['payment_method'] : PosPayment::METHOD_CASH;
                            $posPayment->credit_terms = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['credit_terms'] ?? null) : null;
                            $posPayment->customer_id = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['customer_id'] ?? null) : null;
                            $posPayment->amount = $amt;
                            $posPayment->discount = 0;
                            $posPayment->discount_amount = $amt;
                            $posPayment->created_by = $user_id;
                            $posPayment->save();
                        }
                    } else {
                        $paidAmount = (float) ($request->amount ?? $total);
                        if ($paidAmount <= 0) {
                            $paidAmount = $total;
                        }
                        $changeAmount = (float) ($request->change_amount ?? 0);
                        $posPayment = new PosPayment();
                        $posPayment->pos_id = $pos->id;
                        $posPayment->branch_id = $pos->branch_id;
                        $posPayment->date = $request->date ?? date('Y-m-d');
                        $posPayment->payment_method = $request->payment_method ?? PosPayment::METHOD_CASH;
                        $posPayment->credit_terms = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->credit_terms ?? null) : null;
                        $posPayment->customer_id = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->customer_id ?: $customer_id) : null;
                        $posPayment->transaction_id = $request->transaction_id ?: null;
                        $posPayment->amount = $paidAmount;
                        $posPayment->change_amount = $changeAmount;
                        $posPayment->discount = $discount;
                        $posPayment->discount_amount = $total;
                        $posPayment->notes = $request->notes ?: null;
                        $posPayment->created_by = $user_id;
                        $posPayment->save();
                    }

                    // Phase 2: Create invoice and accounting entries from POS
                    $pos->load('items');
                    $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);

                    session()->forget('pos');

                    return response()->json(
                        [
                            'code' => 200,
                            'success' => __('Payment completed successfully!'),
                            'invoice_id' => $invoice ? $invoice->id : null,
                            'pos_id' => $pos->id,
                        ]
                    );
                }
            else {
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
        if (Auth::user()->canManagePos()) {
            $latest = Pos::where('created_by', '=', \Auth::user()->creatorId())->orderBy('id', 'desc')->first();

            return $latest ? $latest->pos_id + 1 : 1;
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function report()
    {
        if (\Auth::user()->canManagePos()) {

            $posPayments = Pos::where('created_by', '=', \Auth::user()->creatorId())->with(['customer', 'warehouse', 'branch'])->get();

            return view('pos.report', compact('posPayments'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    public function barcode()
    {
        if (\Auth::user()->canManagePos()) {
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
        if (\Auth::user()->canManagePos()) {
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
        if (\Auth::user()->canManagePos()) {
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

    /**
     * Get open order for a table. Cashier: pass branch_id to get order for that table (any waiter's shift). Waiter: pass shift_id for own shift.
     */
    public function getRestaurantOrder(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $tableId = (int) $request->get('table_id');
        $shiftId = (int) $request->get('shift_id');
        $branchId = (int) $request->get('branch_id');
        $creatorId = Auth::user()->creatorId();
        $pos = null;
        if ($tableId && $branchId) {
            $pos = Pos::where('table_id', $tableId)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->whereHas('shift', fn ($q) => $q->where('status', \App\Models\PosShift::STATUS_OPEN))
                ->with(['items.product.posPrinter', 'items.posPrinter'])
                ->first();
        }
        if (!$pos && $tableId && $shiftId) {
            $pos = Pos::where('table_id', $tableId)
                ->where('shift_id', $shiftId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->with(['items.product.posPrinter', 'items.posPrinter'])
                ->first();
        }
        if (!$tableId) {
            return response()->json(['pos_id' => null, 'order_lines' => [], 'session_items' => [], 'shift_id' => null]);
        }
        $orderLines = [];
        if ($pos) {
            foreach ($pos->items as $line) {
                $printer = $line->posPrinter ?? ($line->product ? $line->product->posPrinter : null);
                $orderLines[] = [
                    'line_id' => $line->id,
                    'id' => $line->product_id,
                    'product_id' => $line->product_id,
                    'name' => $line->product ? $line->product->name : '',
                    'quantity' => (int) $line->quantity,
                    'price' => (float) $line->price,
                    'discount' => (float) $line->discount,
                    'tax' => $line->tax,
                    'subtotal' => (float) ($line->price * $line->quantity - $line->discount),
                    'print_zone' => $line->print_zone ?? '',
                    'pos_printer_id' => $printer ? $printer->id : null,
                    'printer_name' => $printer ? $printer->name : null,
                    'status' => $line->status ?? PosProduct::STATUS_PENDING,
                    'sent_at' => $line->sent_at ? $line->sent_at->toIso8601String() : null,
                    'is_sent' => true,
                    'voided_at' => $line->voided_at ? $line->voided_at->toIso8601String() : null,
                    'void_reason' => $line->void_reason ?? null,
                    'pro_image' => $line->product && $line->product->pro_image ? $line->product->pro_image : 'default.png',
                ];
            }
        }
        $shiftIdForCart = $pos ? $pos->shift_id : $shiftId;
        $cartKey = 'pos_restaurant_cart';
        $tableKey = $tableId . '_' . $shiftIdForCart;
        $allCarts = $request->session()->get($cartKey, []);
        $sessionItems = isset($allCarts[$tableKey]) ? array_values($allCarts[$tableKey]) : [];
        $productIds = array_unique(array_filter(array_merge(array_column($orderLines, 'id'), array_column($sessionItems, 'id'))));
        $productsWithPrinter = $productIds ? ProductService::whereIn('id', $productIds)->where('created_by', $creatorId)->with('posPrinter')->get()->keyBy('id') : collect();
        foreach ($orderLines as &$line) {
            if (!isset($line['pos_printer_id']) && isset($productsWithPrinter[$line['id']])) {
                $pr = $productsWithPrinter[$line['id']]->posPrinter;
                $line['pos_printer_id'] = $pr ? $pr->id : null;
                $line['printer_name'] = $pr ? $pr->name : null;
            }
        }
        foreach ($sessionItems as &$item) {
            $item = (array) $item;
            if (isset($productsWithPrinter[$item['id'] ?? 0])) {
                $pr = $productsWithPrinter[$item['id']]->posPrinter;
                $item['pos_printer_id'] = $pr ? $pr->id : null;
                $item['printer_name'] = $pr ? $pr->name : null;
            } else {
                $item['pos_printer_id'] = null;
                $item['printer_name'] = null;
            }
        }
        $sessionItems = array_values($sessionItems);
        return response()->json([
            'pos_id' => $pos ? $pos->id : null,
            'shift_id' => $pos ? $pos->shift_id : $shiftId,
            'order_lines' => $orderLines,
            'session_items' => $sessionItems,
        ]);
    }

    /**
     * Phase 1: Send to production – save order + lines (no payment, no stock deduction).
     * Replaces existing lines with payload so same table can be edited and re-sent. Clears per-table session cart after send.
     */
    public function sendToProduction(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'table_id' => 'required|exists:pos_tables,id',
            'shift_id' => 'required|exists:pos_shifts,id',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:product_services,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;
        $tableId = (int) $request->table_id;
        $shiftId = (int) $request->shift_id;

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (!$branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }

        $warehouse = warehouse::where('created_by', $creatorId)->where('branch_id', $branchId)->first();
        if (!$warehouse) {
            $warehouse = warehouse::where('created_by', $creatorId)->first();
        }
        if (!$warehouse) {
            return response()->json(['error' => __('Please create or select a warehouse.')], 422);
        }

        $pos = Pos::where('table_id', $tableId)
            ->where('shift_id', $shiftId)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->first();

        if (!$pos) {
            $pos = new Pos();
            $pos->pos_id = $this->invoicePosNumber();
            $pos->branch_id = $branchId;
            $pos->order_type = Pos::ORDER_TYPE_DINE_IN;
            $pos->table_id = $tableId;
            $pos->shift_id = $shiftId;
            $pos->customer_id = 0;
            $pos->warehouse_id = $warehouse->id;
            $pos->pos_date = date('Y-m-d');
            $pos->payment_method = null;
            $pos->created_by = $creatorId;
            $pos->pos_user_id = session('pos_pin_user_id', Auth::id());
            $pos->save();
        }

        // Add only new lines (do not delete or change already-sent lines). Request items = unsent items to send.
        $now = now();
        foreach ($request->items as $item) {
            $product = ProductService::where('id', $item['id'])->where('created_by', $creatorId)->first();
            if (!$product) {
                continue;
            }
            $printZone = $product->print_zone ?? PosProduct::PRINT_ZONE_KITCHEN;
            if ($printZone !== PosProduct::PRINT_ZONE_BAR && $printZone !== PosProduct::PRINT_ZONE_BOTH) {
                $printZone = PosProduct::PRINT_ZONE_KITCHEN;
            }
            $taxId = is_string($product->tax_id ?? null) ? $product->tax_id : ($product->tax_id ?? '0');
            PosProduct::create([
                'pos_id' => $pos->id,
                'product_id' => $product->id,
                'quantity' => (int) $item['quantity'],
                'price' => (float) $item['price'],
                'tax' => $taxId,
                'discount' => (float) ($item['discount'] ?? 0),
                'description' => $item['description'] ?? null,
                'status' => PosProduct::STATUS_PENDING,
                'sent_at' => $now,
                'print_zone' => $printZone,
                'pos_printer_id' => $product->pos_printer_id ?? null,
            ]);
        }

        // Clear per-table session cart so UI loads from DB next time.
        $cartKey = 'pos_restaurant_cart';
        $tableKey = $tableId . '_' . $shiftId;
        $allCarts = $request->session()->get($cartKey, []);
        if (isset($allCarts[$tableKey])) {
            unset($allCarts[$tableKey]);
            $request->session()->put($cartKey, $allCarts);
        }

        \App\Services\PosAuditLogService::log(
            PosAuditLog::ACTION_SEND_ORDER,
            'pos',
            (int) $pos->id,
            __('Order sent to production'),
            ['table_id' => $pos->table_id, 'items_count' => count($request->items)]
        );
        return response()->json([
            'success' => true,
            'message' => __('Order sent to production.'),
            'pos_id' => $pos->id,
        ]);
    }

    /**
     * Phase 4: Cancel order (open order, no payment). Requires manager password.
     */
    public function cancelOrder(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        if (!PosAuditLogService::requireManagerPassword($request)) {
            return response()->json(['error' => __('Manager password required to cancel order.')], 403);
        }
        $request->validate(['pos_id' => 'required|exists:pos,id']);
        $pos = Pos::where('id', $request->pos_id)->where('created_by', Auth::user()->creatorId())->first();
        if (!$pos) {
            return response()->json(['error' => __('Order not found.')], 404);
        }
        if ($pos->posPayments()->exists()) {
            return response()->json(['error' => __('Cannot cancel order that has payments.')], 422);
        }
        $pos->cancelled_at = now();
        $pos->save();
        PosProduct::where('pos_id', $pos->id)->update(['status' => PosProduct::STATUS_CANCELLED]);
        PosAuditLogService::log(PosAuditLog::ACTION_CANCEL_ORDER, 'pos', (int) $pos->id, __('Order cancelled'), ['pos_id' => $pos->pos_id, 'table_id' => $pos->table_id]);
        return response()->json(['success' => true, 'message' => __('Order cancelled.')]);
    }

    /**
     * Void a sent line (manager/cashier only). Reason is mandatory; void appears in shift report.
     */
    public function voidRestaurantLine(Request $request, $line)
    {
        $user = Auth::user();
        if (!$user->canManagePos() || (!$user->can('pos restaurant full') && !$user->can('pos restaurant manager'))) {
            return response()->json(['error' => __('Only Manager can void items.')], 403);
        }
        $request->validate(['reason' => 'required|string|max:500']);
        $posProduct = PosProduct::where('id', $line)->whereHas('pos', function ($q) use ($user) {
            $q->where('created_by', $user->creatorId());
        })->with('pos')->first();
        if (!$posProduct) {
            return response()->json(['error' => __('Line not found.')], 404);
        }
        $pos = $posProduct->pos;
        if ($pos->posPayments()->exists()) {
            return response()->json(['error' => __('Cannot void lines on a paid order.')], 422);
        }
        if (!$posProduct->sent_at) {
            return response()->json(['error' => __('Only sent items can be voided.')], 422);
        }
        if ($posProduct->voided_at) {
            return response()->json(['error' => __('This line is already voided.')], 422);
        }
        $posProduct->status = PosProduct::STATUS_CANCELLED;
        $posProduct->voided_at = now();
        $posProduct->void_reason = $request->reason;
        $posProduct->voided_by = $user->id;
        $posProduct->save();
        PosAuditLogService::log(
            PosAuditLog::ACTION_VOID_LINE,
            'pos_product',
            (int) $posProduct->id,
            __('Line voided: :reason', ['reason' => $request->reason]),
            ['pos_id' => $pos->id, 'line_id' => $posProduct->id]
        );
        return response()->json(['success' => true, 'message' => __('Line voided.')]);
    }

    /**
     * Join tables: merge one or more source table orders into the current (target) table.
     * Target table must be selected. Source tables must be different, have open unpaid orders in same branch.
     */
    public function joinRestaurantTables(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'target_table_id' => 'required|exists:pos_tables,id',
            'target_shift_id' => 'required|exists:pos_shifts,id',
            'source_table_ids' => 'required|array',
            'source_table_ids.*' => 'exists:pos_tables,id',
        ]);
        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;
        $targetTableId = (int) $request->target_table_id;
        $targetShiftId = (int) $request->target_shift_id;
        $sourceTableIds = array_unique(array_map('intval', $request->source_table_ids));

        if (in_array($targetTableId, $sourceTableIds)) {
            return response()->json(['error' => __('Target table cannot be in source tables.')], 422);
        }

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (!$branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }
        $warehouse = warehouse::where('created_by', $creatorId)->where('branch_id', $branchId)->first();
        if (!$warehouse) {
            $warehouse = warehouse::where('created_by', $creatorId)->first();
        }
        if (!$warehouse) {
            return response()->json(['error' => __('Please create or select a warehouse.')], 422);
        }

        $targetPos = Pos::where('table_id', $targetTableId)
            ->where('shift_id', $targetShiftId)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->first();

        if (!$targetPos) {
            $targetPos = new Pos();
            $targetPos->pos_id = $this->invoicePosNumber();
            $targetPos->branch_id = $branchId;
            $targetPos->order_type = Pos::ORDER_TYPE_DINE_IN;
            $targetPos->table_id = $targetTableId;
            $targetPos->shift_id = $targetShiftId;
            $targetPos->customer_id = 0;
            $targetPos->warehouse_id = $warehouse->id;
            $targetPos->pos_date = date('Y-m-d');
            $targetPos->payment_method = null;
            $targetPos->created_by = $creatorId;
            $targetPos->pos_user_id = session('pos_pin_user_id', Auth::id());
            $targetPos->save();
        }

        $openShiftIds = \App\Models\PosShift::where('branch_id', $branchId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', $creatorId)
            ->pluck('id');

        $movedCount = 0;
        $cartKey = 'pos_restaurant_cart';
        $allCarts = $request->session()->get($cartKey, []);

        foreach ($sourceTableIds as $srcTableId) {
            $sourcePos = Pos::where('table_id', $srcTableId)
                ->where('branch_id', $branchId)
                ->whereIn('shift_id', $openShiftIds)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->with('items')
                ->first();
            if (!$sourcePos || $sourcePos->id === $targetPos->id) {
                continue;
            }
            foreach ($sourcePos->items as $item) {
                $item->pos_id = $targetPos->id;
                $item->save();
                $movedCount++;
            }
            $sourcePos->cancelled_at = now();
            $sourcePos->save();
            \App\Models\PosTable::where('id', $srcTableId)->where('created_by', $creatorId)->update(['status' => 'available']);
            foreach ($openShiftIds as $sid) {
                $tableKey = $srcTableId . '_' . $sid;
                if (isset($allCarts[$tableKey])) {
                    unset($allCarts[$tableKey]);
                }
            }
        }
        $request->session()->put($cartKey, $allCarts);

        if ($movedCount > 0) {
            PosAuditLogService::log(
                PosAuditLog::ACTION_SEND_ORDER,
                'pos',
                (int) $targetPos->id,
                __('Tables joined'),
                ['target_table_id' => $targetTableId, 'source_table_ids' => $sourceTableIds, 'items_moved' => $movedCount]
            );
        }

        return response()->json([
            'success' => true,
            'message' => $movedCount > 0 ? __('Orders merged successfully.') : __('No orders to merge.'),
            'pos_id' => $targetPos->id,
        ]);
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

    //for thermal print (optional pos_id for receipt after payment with payment details)
    public function printView(Request $request)
    {
        $user = Auth::user();
        $settings = Utility::settings();
        $posPayments = collect();

        if ($request->pos_id && Auth::user()->canManagePos()) {
            $pos = Pos::with(['items', 'customer', 'warehouse', 'posPayments'])->where('id', $request->pos_id)->where('created_by', $user->creatorId())->first();
            if ($pos) {
                $posPayments = $pos->posPayments;
                $customer = $pos->customer_id ? $pos->customer : null;
                $warehouse = $pos->warehouse;
                $details = [
                    'pos_id' => $user->posNumberFormat($pos->pos_id),
                    'customer' => $customer ? $customer->toArray() : [],
                    'warehouse' => $warehouse ? $warehouse->toArray() : [],
                    'user' => $user->toArray(),
                    'date' => $pos->pos_date ?? date('Y-m-d'),
                ];
                $warehouseName = $details['warehouse']['name'] ?? ($warehouse ? $warehouse->name : '-');
                $details['warehouse']['details'] = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';
                if (!empty($details['customer']['name'])) {
                    $details['customer']['details'] = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '</h6>';
                } else {
                    $details['customer']['details'] = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b></h2>';
                }
                $details['customer']['shippdetails'] = '-';
                $details['user']['details'] = '<h6 class="text-dark"><b>' . ucfirst($user->name) . '</b></h6>';
                $sales = ['data' => [], 'discount' => '0', 'sub_total' => '0', 'total' => '0'];
                $mainsubtotal = 0;
                $discount = 0;
                foreach ($pos->items as $item) {
                    $product = ProductService::find($item->product_id);
                    $price = (float) $item->price;
                    $qty = (int) $item->quantity;
                    $taxRate = (float) Utility::totalTaxRate($item->tax);
                    $subtotal = $price * $qty;
                    $tax = ($subtotal * $taxRate) / 100;
                    $lineTotal = $subtotal + $tax;
                    $mainsubtotal += $lineTotal;
                    $sales['data'][] = [
                        'name' => $product ? $product->name : __('Product'),
                        'quantity' => $qty,
                        'price' => $user->priceFormat($price),
                        'tax' => $taxRate . '%',
                        'product_tax' => $taxRate . '%',
                        'tax_amount' => $user->priceFormat($tax),
                        'subtotal' => $user->priceFormat($lineTotal),
                    ];
                }
                $sales['discount'] = $user->priceFormat($discount);
                $sales['sub_total'] = $user->priceFormat($mainsubtotal);
                $sales['total'] = $user->priceFormat($mainsubtotal - $discount);
                $productServices = ProductService::where('created_by', $user->creatorId())->get();
                $barcode = ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()];
                return view('pos.printview', compact('details', 'sales', 'customer', 'productServices', 'barcode', 'posPayments'));
            }
        }

        $sess = session()->get('pos');
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

        $warehouseName = $details['warehouse']['name'] ?? ($warehouse ? $warehouse->name : '-');
        $warehousedetails = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';

        if (!empty($details['customer'])) {
            $details['customer']['billing_state'] = $details['customer']['billing_state'] != '' ? ", " . $details['customer']['billing_state'] : '';
            $details['customer']['shipping_state'] = $details['customer']['shipping_state'] != '' ? ", " . $details['customer']['shipping_state'] : '';
            $customerdetails = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_phone'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_city'] ?? '') . ($details['customer']['billing_state'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_country'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_zip'] ?? '') . '</p></h6>';
            $shippdetails = '<h6 class="text-dark"><b>' . ucfirst($details['customer']['name']) . '</b>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_phone'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_city'] ?? '') . ($details['customer']['shipping_state'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_country'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_zip'] ?? '') . '</p></h6>';

        } else {
            $customerdetails = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b><h2>';
            $shippdetails = '-';

        }

        $settings['company_telephone'] = $settings['company_telephone'] != '' ? ", " . $settings['company_telephone'] : '';
        $settings['company_state'] = $settings['company_state'] != '' ? ", " . $settings['company_state'] : '';

        $userName = $details['user']['name'] ?? ($user ? $user->name : '-');
        $userdetails = '<h6 class="text-dark"><b>' . ucfirst($userName) . ' </b> <h2  class="font-weight-normal">' . '<p class="m-0 font-weight-normal">' . $settings['company_name'] . $settings['company_telephone'] . '</p>' . '<p class="m-0 font-weight-normal">' . $settings['company_address'] . '</p>' . '<p class="m-0 h6 font-weight-normal">' . $settings['company_city'] . $settings['company_state'] . '</p>' . '<p class="m-0 font-weight-normal">' . $settings['company_country'] . '</p>' . '<p class="m-0 font-weight-normal">' . $settings['company_zipcode'] . '</p></h2>';

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

        return view('pos.printview', compact('details', 'sales', 'customer', 'productServices', 'barcode', 'posPayments'));

    }
    
    public function add_opening_balance()
    {
        // Opening balance is set when a shift is started (POS Shifts). Redirect to shifts.
        return redirect()->route('pos-shift.index')->with('info', __('pos.opening_balance_set_on_shift_start'));
    }

    
    public function add_closing_balance()
    {
        $userOpenShift = \App\Models\PosShift::where('user_id', \Auth::user()->id)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', \Auth::user()->creatorId())
            ->orderBy('opened_at', 'desc')
            ->first();
        if (!$userOpenShift) {
            return redirect()->back()->with('error', __('pos.open_shift_required_to_add_closing'));
        }
        return view('pos.add_closing_balance', compact('userOpenShift'));
    }
    
    public function save_closing_balance(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'shift_id' => 'nullable|exists:pos_shifts,id',
            'tips' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);
        $shiftId = $request->shift_id;
        $shift = $shiftId
            ? \App\Models\PosShift::where('id', $shiftId)->where('user_id', \Auth::user()->id)->where('status', \App\Models\PosShift::STATUS_OPEN)->first()
            : \App\Models\PosShift::where('user_id', \Auth::user()->id)->where('status', \App\Models\PosShift::STATUS_OPEN)->where('created_by', \Auth::user()->creatorId())->orderBy('opened_at', 'desc')->first();
        if (!$shift) {
            return redirect()->route('pos.dashboard')->with('error', __('pos.open_shift_required_to_add_closing'));
        }

        $posIds = Pos::where('shift_id', $shift->id)->pluck('id');
        $salesCount = $posIds->count();
        $salesTotal = 0;
        foreach (Pos::where('shift_id', $shift->id)->get() as $pos) {
            $salesTotal += $pos->getTotal();
        }
        $cashTotal = PosPayment::whereIn('pos_id', $posIds)->where('payment_method', 'cash')->sum('amount');
        $cardTotal = PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = PosPayment::whereIn('pos_id', $posIds)->where('payment_method', 'credit')->sum('amount');
        $opening = (float) ($shift->opening_balance ?? 0);
        $closing = (float) $request->amount;
        $difference = $closing - ($opening + $cashTotal);

        $shift->closing_balance = $closing;
        $shift->sales_count = $salesCount;
        $shift->sales_total = round($salesTotal, 2);
        $shift->cash_total = round($cashTotal, 2);
        $shift->card_total = round($cardTotal, 2);
        $shift->credit_total = round($creditTotal, 2);
        $shift->difference = round($difference, 2);
        $shift->tips = (float) ($request->tips ?? 0);
        $shift->notes = $request->notes ? trim($request->notes) : null;
        $shift->status = \App\Models\PosShift::STATUS_CLOSED;
        $shift->closed_at = now();
        $shift->save();

        return redirect()->route('pos-shift.index')->with('success', __('Closing balance saved and shift closed.'));
    }

    
    public function balances()
    {
        $shifts = \App\Models\PosShift::where('created_by', \Auth::user()->creatorId())
            ->with(['branch', 'user'])
            ->orderBy('opened_at', 'desc')
            ->paginate(20);
        return view('pos.balances', compact('shifts'));
    }


    /**
     * Restaurant POS: validate PIN and set session. Called from PIN entry form.
     */
    public function validateRestaurantPin(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $pin = $request->get('pin');
        $pin = is_string($pin) ? preg_replace('/\D/', '', $pin) : '';
        if (strlen($pin) < 4 || strlen($pin) > 8) {
            return response()->json(['success' => false, 'error' => __('PIN must be 4-8 digits.')]);
        }
        $creatorId = Auth::user()->creatorId();
        $user = User::where('created_by', $creatorId)
            ->where('pos_pin', $pin)
            ->first();
        if (!$user) {
            return response()->json(['success' => false, 'error' => __('Invalid PIN.')]);
        }
        $request->session()->put('pos_pin_user_id', $user->id);
        $openShift = \App\Models\PosShift::where('user_id', $user->id)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', $creatorId)
            ->orderBy('opened_at', 'desc')
            ->first();
        return response()->json([
            'success' => true,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'has_open_shift' => (bool) $openShift,
            'open_shift' => $openShift ? ['id' => $openShift->id, 'branch_id' => $openShift->branch_id] : null,
        ]);
    }

    /**
     * Restaurant POS: clear PIN user and return to PIN entry.
     */
    public function logoutRestaurantPin(Request $request)
    {
        $request->session()->forget('pos_pin_user_id');
        return redirect()->route('pos.restaurant.index');
    }

    /**
     * Get current PIN user for restaurant POS (from session). Returns null if not set or invalid.
     */
    protected function getRestaurantPinUser()
    {
        $id = session('pos_pin_user_id');
        if (!$id) {
            return null;
        }
        $user = User::find($id);
        if (!$user || $user->created_by != Auth::user()->creatorId()) {
            session()->forget('pos_pin_user_id');
            return null;
        }
        return $user;
    }

    public function indexRestaurant($id = 0)
    {
        if (!Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = Auth::user()->creatorId();
        $posPinUser = $this->getRestaurantPinUser();
        if (!$posPinUser) {
            return view('pos.pin-entry');
        }
        // Use PIN user for all POS actions (shift, orders). Company scope still via creatorId.
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
        $customers = Customer::where('created_by', $creatorId)->get()->pluck('name', 'name');
        $customers->prepend('Walk-in-customer', '');
        $restaurantBranchIds = Branch::where('created_by', $creatorId)->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)->pluck('id')->toArray();
        $posBranchLocked = $posPinUser->pos_branch_id ? true : false;
        if ($posBranchLocked && $posPinUser->pos_branch_id) {
            $restaurantBranchIds = array_intersect($restaurantBranchIds, [$posPinUser->pos_branch_id]);
        }
        $branches = Branch::whereIn('id', $restaurantBranchIds)->get()->pluck('name', 'id');
        $warehouses = warehouse::with('branch')->where('created_by', $creatorId)
            ->whereIn('branch_id', $restaurantBranchIds)->get()->pluck('name', 'id');
        $hasRetailBranches = false;
        $hasRestaurantBranches = !empty($restaurantBranchIds);
        $posMode = 'restaurant';
        $user = $posPinUser;
        $details = [
            'pos_id' => $user->posNumberFormat($this->invoicePosNumber()),
            'customer' => $customers != null ? $customers->toArray() : [],
            'user' => $user->toArray(),
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
        $canChangeShift = $user->can('pos restaurant full') || $user->can('pos restaurant manager') || $user->can('manage pos');
        $userOpenShifts = \App\Models\PosShift::where('user_id', $user->id)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', $creatorId)
            ->orderBy('opened_at', 'desc')
            ->get();
        $userOpenShiftByBranch = $userOpenShifts->pluck('id', 'branch_id')->toArray();
        $userOpenShift = $userOpenShifts->first();
        $canShowPayButton = $user->can('pos restaurant full') || $user->can('pos restaurant manager') || $user->can('pos restaurant cashier') || $user->can('manage pos');
        $canShowVoid = $user->can('pos restaurant full') || $user->can('pos restaurant manager') || $user->can('manage pos');
        return view('pos.index-restaurant', compact('customers', 'branches', 'warehouses', 'details', 'customer', 'warehouseId', 'id', 'restaurantBranchIds', 'hasRetailBranches', 'hasRestaurantBranches', 'posMode', 'canChangeShift', 'userOpenShiftByBranch', 'userOpenShift', 'posBranchLocked', 'canShowPayButton', 'canShowVoid', 'posPinUser'));
    }

}
