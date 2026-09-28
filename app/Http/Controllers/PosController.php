<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentVoidLog;
use App\Models\Plan;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\PosBalance;
use App\Models\PosAuditLog;
use App\Services\BusinessDayLockService;
use App\Services\PosActorService;
use App\Services\DocumentVoidService;
use App\Services\DocumentLayoutRenderer;
use App\Services\PosAuditLogService;
use App\Services\InvoiceSaleDeductionService;
use App\Services\PosKitDeductionService;
use App\Services\ProductOptionService;
use App\Services\PosFefoDeductionService;
use App\Services\PosRecipeDeductionService;
use App\Services\PosPrintRoutingService;
use App\Services\PosPrinterAgentService;
use App\Services\PosRestaurantRoleService;
use App\Services\PosToInvoiceService;
use App\Models\QuotationProduct;
use App\Models\StockReport;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\PosArea;
use App\Models\PosTable;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Modules\HotelManagement\Entities\HotelAccountingSetting;
use Modules\HotelManagement\Entities\HotelFolio;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\TransactionLines;
use Modules\HotelManagement\Services\HotelPosChargeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PosController extends Controller
{
    protected function isCompanyLogin(): bool
    {
        return PosActorService::isCompanyLogin();
    }

    /**
     * Restaurant POS actor (PIN user on terminal, or company owner).
     */
    protected function restaurantPosActor(): User
    {
        $actor = PosActorService::restaurantActor();
        if (! $actor) {
            abort(403, __('pos.pin_sign_in_required'));
        }

        return $actor;
    }

    /**
     * @return \Illuminate\Http\JsonResponse|User
     */
    protected function restaurantPinActorOrJsonError()
    {
        $actor = PosActorService::restaurantActor();
        if (! $actor) {
            return response()->json(['error' => __('pos.pin_sign_in_required')], 403);
        }

        return $actor;
    }

    /**
     * Resolve POS line tax as a percentage rate (not tax_id stored on the row).
     */
    protected function resolvePosLineTaxRate(?PosProduct $line, ?ProductService $product): float
    {
        $taxRate = 0.0;
        $taxIds = [];
        if ($product && ! empty($product->tax_id)) {
            $taxIds = array_filter(array_map('trim', explode(',', (string) $product->tax_id)));
        } elseif ($line && ! empty($line->tax) && ! is_numeric($line->tax)) {
            $taxIds = array_filter(array_map('trim', explode(',', (string) $line->tax)));
        } elseif ($line && is_numeric($line->tax)) {
            $maybeId = (int) $line->tax;
            $taxModel = \App\Models\Tax::find($maybeId);
            if ($taxModel) {
                return (float) ($taxModel->rate ?? 0);
            }

            return (float) $line->tax;
        }
        if ($taxIds !== []) {
            foreach (\App\Models\Tax::whereIn('id', $taxIds)->get() as $t) {
                $taxRate += (float) ($t->rate ?? 0);
            }
        }

        return $taxRate;
    }

    /**
     * Human-readable cart/order line name (product + variant + options).
     */
    protected function resolvePosLineDisplayName(?PosProduct $line, ?ProductService $product): string
    {
        $productId = $line ? (int) $line->product_id : 0;
        if (! $product && $productId > 0) {
            $product = ProductService::find($productId);
        }
        $base = $product ? trim((string) $product->name) : '';
        if ($line && ! empty($line->selected_options_json) && $product) {
            $selected = ProductOptionService::normalizeSelectedOptions($line->selected_options_json);
            if ($selected !== []) {
                $formatted = trim(ProductOptionService::formatLineName($product, $selected));
                if ($formatted !== '') {
                    return $formatted;
                }
            }
        }
        if ($line && $product && ! empty($line->product_variant_id)) {
            $variant = $line->relationLoaded('variant') ? $line->variant : ProductVariant::find($line->product_variant_id);
            if ($variant) {
                $base = trim($base . ' — ' . $variant->displayLabel($product));
            }
        }
        if ($base === '') {
            return $productId > 0 ? (__('Product') . ' #' . $productId) : __('Product');
        }

        return $base;
    }

    /**
     * Ensure a POS line has a valid product_variant_id when the product uses variants.
     * Auto-assigns when exactly one variant exists. Returns a user-facing error or null.
     *
     * @return string|null Error message, or null when OK / not applicable
     */
    protected function ensurePosLineProductVariant(?PosProduct $line, ?ProductService $product, bool $persist = true): ?string
    {
        if (! $line || ! $product || ! ($product->variants_enabled ?? false)) {
            return null;
        }

        $productName = trim((string) ($product->name ?? '')) ?: (__('Product') . ' #' . $product->id);
        $lvid = (int) ($line->product_variant_id ?? 0);
        if ($lvid > 0 && ProductVariant::where('id', $lvid)->where('product_service_id', $product->id)->exists()) {
            return null;
        }

        $variants = ProductVariant::where('product_service_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            // Flag enabled but no variants defined — do not block payment/send.
            if ($lvid > 0 && $persist) {
                $line->product_variant_id = null;
                $line->save();
            }

            return null;
        }

        if ($variants->count() === 1) {
            $line->product_variant_id = (int) $variants->first()->id;
            if ($persist) {
                $line->save();
            }

            return null;
        }

        return __('pos.variant_required_for_product', ['product' => $productName]);
    }

    /**
     * Resolve variant id from request item for a variants-enabled product.
     * Returns [variantId|null, errorMessage|null].
     *
     * @return array{0: int|null, 1: string|null}
     */
    protected function resolveRequestProductVariantId(ProductService $product, array $item): array
    {
        if (! ($product->variants_enabled ?? false)) {
            $vid = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            if ($vid !== null && $vid <= 0) {
                $vid = null;
            }

            return [$vid, null];
        }

        $productName = trim((string) ($product->name ?? '')) ?: (__('Product') . ' #' . $product->id);
        $vid = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : 0;
        if ($vid > 0 && ProductVariant::where('id', $vid)->where('product_service_id', $product->id)->exists()) {
            return [$vid, null];
        }

        $variants = ProductVariant::where('product_service_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            return [null, null];
        }

        if ($variants->count() === 1) {
            return [(int) $variants->first()->id, null];
        }

        return [null, __('pos.variant_required_for_product', ['product' => $productName])];
    }

    /**
     * Branch dropdown/data scope should follow current POS actor assignments (PIN user).
     */
    protected function restaurantBranchScopeUser(): User
    {
        return $this->restaurantPosActor();
    }

    protected function assertRestaurantPosActorBranch(int $branchId): ?\Illuminate\Http\JsonResponse
    {
        if (! $branchId) {
            return null;
        }
        $scopeUser = $this->restaurantBranchScopeUser();
        if (! $scopeUser->canAccessBranchForPos($branchId, 'restaurant')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        return null;
    }

    protected function resolvePosDocumentTypeFromRequest(Request $request): string
    {
        return Pos::normalizeDocumentType(
            $request->input('document_type') ?? $request->input('documentType'),
            $request->input('payment_method')
        );
    }

    /**
     * Exclusive unit price for a POS line, using cost when document type is Internal Consumption.
     */
    protected function resolvePosLineUnitPriceForDocument(
        ?ProductService $product,
        string $documentType,
        float $cartUnitPrice,
        ?int $variantId = null,
        string $priceType = 'exclusive',
        float $taxRate = 0.0
    ): float {
        if ($product && $documentType === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
            $costRaw = null;
            if ($variantId && ($product->variants_enabled ?? false)) {
                $variant = $product->variants()->where('id', $variantId)->first();
                if ($variant && $variant->purchase_price !== null) {
                    $costRaw = (float) $variant->purchase_price;
                }
            }
            if ($costRaw === null) {
                $costRaw = (float) ($product->purchase_price ?? 0);
            }

            return (float) $product->exclusivePurchaseUnitPrice($costRaw);
        }

        $unitPrice = $cartUnitPrice;
        if ($priceType === 'inclusive' && $taxRate > 0) {
            $unitPrice = $unitPrice / (1 + $taxRate / 100);
        }

        return (float) $unitPrice;
    }

    /**
     * Active (billable) POS line: not voided and not removed from cart by admin.
     * Voided lines stay in DB for audit but must not appear on bills/slips or be charged.
     */
    protected function isActivePosBillLine($line): bool
    {
        return empty($line->voided_at) && empty($line->removed_from_cart_at);
    }

    /**
     * Rewrite existing POS line prices to exclusive cost for Internal Consumption.
     * Returns the inclusive bill total (price * qty * (1 + tax%)).
     */
    protected function applyInternalConsumptionCostToPosItems(Pos $pos, int $userId): float
    {
        $total = 0.0;
        $pos->loadMissing('items');
            foreach ($pos->items as $line) {
                if (! $this->isActivePosBillLine($line)) {
                    continue;
                }
            $product = ProductService::whereId($line->product_id)->where('created_by', $userId)->first();
            if (! $product) {
                $total += (float) $line->price * (float) $line->quantity;
                continue;
            }
            $variantId = $line->product_variant_id ? (int) $line->product_variant_id : null;
            $unitCost = $this->resolvePosLineUnitPriceForDocument(
                $product,
                Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION,
                (float) $line->price,
                $variantId
            );
            $line->price = $unitCost;
            $line->save();

            $taxRate = 0.0;
            if (! empty($product->tax_id)) {
                $taxIds = array_filter(array_map('trim', explode(',', (string) $product->tax_id)));
                if (! empty($taxIds)) {
                    $taxRate = (float) \App\Models\Tax::whereIn('id', $taxIds)->sum('rate');
                }
            }
            $total += ($unitCost * (float) $line->quantity) * (1 + ($taxRate / 100));
        }

        return round($total, 2);
    }

    /**
     * Exclusive cost unit price for payment-modal / cart display.
     */
    protected function resolveProductCostUnitPrice(?ProductService $product, ?int $variantId = null): float
    {
        if (! $product) {
            return 0.0;
        }

        return $this->resolvePosLineUnitPriceForDocument(
            $product,
            Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION,
            0.0,
            $variantId
        );
    }

    /**
     * Restaurant branch ids visible in POS for the current actor (PIN user or company login).
     *
     * @return int[]
     */
    protected function resolveRestaurantBranchIdsForActor(User $actor, int $creatorId): array
    {
        $pool = Branch::where('created_by', $creatorId)
            ->where('is_active', true)
            ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $allowed = $actor->filterBranchIdsForPosAccess($pool);
        if ($allowed !== []) {
            return $allowed;
        }

        $actor->loadMissing('branches');
        $fromPivot = $actor->branches
            ->filter(fn (Branch $b) => (int) $b->created_by === $creatorId && $b->is_active && $b->isRestaurant())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
        if ($fromPivot !== []) {
            return array_values(array_intersect($pool, $fromPivot));
        }

        $assignedId = (int) ($actor->pos_branch_id ?? 0);
        if ($assignedId > 0) {
            $assigned = Branch::where('id', $assignedId)
                ->where('created_by', $creatorId)
                ->where('is_active', true)
                ->first();
            if ($assigned) {
                if (! $assigned->isRestaurant()) {
                    PosRestaurantRoleService::ensureRestaurantPosTypeForPosBranchAssignment($assignedId, $creatorId);
                }

                return [$assignedId];
            }
        }

        return $actor->can('manage pos') ? $pool : [];
    }

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
            $customers = Customer::forPos()->get()->pluck('name', 'name');
            $customers->prepend('Walk-in-customer', '');
            $customersForPos = Customer::forPos()->get(['id', 'name', 'email', 'contact', 'is_walk_in']);
            $customersForPos = collect([(object)['id' => 0, 'name' => 'Walk-in-customer', 'email' => 'walkin@default.com', 'contact' => '0000000000', 'is_walk_in' => true]])->merge($customersForPos);
            // Retail POS: only retail branches (pos_type = retail or null)
            $retailBranchIds = Branch::where('created_by', $creatorId)->where('is_active', true)
                ->where(function ($q) {
                    $q->where('pos_type', Branch::POS_TYPE_RETAIL)->orWhereNull('pos_type');
                })
                ->pluck('id')->toArray();
            $retailBranchIds = Auth::user()->filterBranchIdsForPosAccess($retailBranchIds);
            $posBranchLocked = count($retailBranchIds) === 1;
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
            $canGivePosCredit = $user->can('give pos credit') || $user->can('manage pos');
            $posCreditTermOptions = ['7 days' => '7 ' . __('Days'), '15 days' => '15 ' . __('Days'), '30 days' => '30 ' . __('Days'), '90 days' => '90 ' . __('Days'), '120 days' => '120 ' . __('Days')];
            $userOpenShifts = \App\Models\PosShift::where('user_id', $user->id)
                ->where('status', \App\Models\PosShift::STATUS_OPEN)
                ->where('created_by', $creatorId)
                ->orderBy('opened_at', 'desc')
                ->get();
            $userOpenShiftByBranch = $userOpenShifts->pluck('id', 'branch_id')->toArray();
            $userOpenShift = $userOpenShifts->first();
            $defaultBranchIdFromShift = $userOpenShift ? $userOpenShift->branch_id : null;
            if ($defaultBranchIdFromShift && $id == 0) {
                $branchForDefault = Branch::where('id', $defaultBranchIdFromShift)
                    ->where('created_by', $creatorId)
                    ->first();
                $wh = $branchForDefault
                    ? $branchForDefault->resolveDefaultWarehouseId()
                    : warehouse::where('branch_id', $defaultBranchIdFromShift)->where('created_by', $creatorId)->value('id');
                if ($wh) {
                    $warehouseId = $wh;
                }
            }
            return view('pos.index', compact('customers', 'customersForPos', 'branches', 'warehouses', 'details', 'customer', 'warehouseId', 'id', 'restaurantBranchIds', 'hasRetailBranches', 'hasRestaurantBranches', 'posMode', 'userOpenShift', 'canChangeShift', 'canGivePosCredit', 'posCreditTermOptions', 'userOpenShiftByBranch', 'defaultBranchIdFromShift', 'posBranchLocked'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Open order totals for dine-in tables (sent lines + unsent session cart).
     * Matches how opening a table loads the order: any unpaid check on an open shift
     * for the branch (not only the currently selected shift_id).
     *
     * @param  int[]  $tableIds
     * @return array<int, float> table_id => total
     */
    protected function computeRestaurantTableCartTotalsBatch(Request $request, array $tableIds, int $branchId, int $shiftId): array
    {
        $tableIds = array_values(array_unique(array_filter(array_map('intval', $tableIds))));
        $totals = [];
        foreach ($tableIds as $tid) {
            $totals[$tid] = 0.0;
        }
        if ($tableIds === []) {
            return $totals;
        }

        $creatorId = Auth::user()->creatorId();
        $posQuery = Pos::whereIn('table_id', $tableIds)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->with(['items.product']);

        if ($branchId > 0) {
            $posQuery->where('branch_id', $branchId);
        }

        $posQuery->where(function ($q) use ($shiftId) {
            $q->whereHas('shift', function ($sq) {
                $sq->where('status', \App\Models\PosShift::STATUS_OPEN);
            });
            if ($shiftId > 0) {
                $q->orWhere('shift_id', $shiftId);
            }
        });

        foreach ($posQuery->get() as $pos) {
            $tid = (int) $pos->table_id;
            if (! isset($totals[$tid])) {
                $totals[$tid] = 0.0;
            }
            foreach ($pos->items as $line) {
                if ($line->voided_at || $line->removed_from_cart_at) {
                    continue;
                }
                $taxRate = $this->resolvePosLineTaxRate($line, $line->product);
                $lineNet = (float) $line->price * (int) $line->quantity - (float) $line->discount;
                $totals[$tid] += $lineNet * (1 + $taxRate / 100);
            }
        }

        $allCarts = $request->session()->get('pos_restaurant_cart', []);
        if (! is_array($allCarts) || $allCarts === []) {
            return $totals;
        }

        foreach ($tableIds as $tableId) {
            $keys = [];
            if ($shiftId > 0) {
                $keys[] = $tableId . '_' . $shiftId;
            }
            foreach (array_keys($allCarts) as $key) {
                if (preg_match('/^' . preg_quote((string) $tableId, '/') . '_\d+$/', (string) $key)) {
                    $keys[] = $key;
                }
            }
            foreach (array_unique($keys) as $tableKey) {
                if (empty($allCarts[$tableKey]) || ! is_array($allCarts[$tableKey])) {
                    continue;
                }
                foreach ($allCarts[$tableKey] as $v) {
                    $v = (array) $v;
                    $sub = (float) ($v['subtotal'] ?? 0);
                    if ($sub <= 0) {
                        $qty = (int) ($v['quantity'] ?? 1);
                        $price = (float) ($v['price'] ?? 0);
                        $tax = (float) ($v['tax'] ?? 0);
                        $sub = $price * $qty * (1 + $tax / 100);
                    }
                    $totals[$tableId] += $sub;
                }
            }
        }

        return $totals;
    }

    /**
     * @deprecated Use computeRestaurantTableCartTotalsBatch for layout grids.
     */
    protected function computeRestaurantTableCartTotal(Request $request, int $tableId, int $shiftId, int $branchId = 0): float
    {
        $batch = $this->computeRestaurantTableCartTotalsBatch($request, [$tableId], $branchId, $shiftId);

        return (float) ($batch[$tableId] ?? 0.0);
    }

    protected function closeRestaurantTableIfFullyPaid(int $tableId, int $userId): void
    {
        $hasOpenChecks = Pos::where('table_id', $tableId)
            ->where('created_by', $userId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->exists();

        if (! $hasOpenChecks) {
            PosTable::where('id', $tableId)->where('created_by', $userId)->update([
                'status' => PosTable::STATUS_AVAILABLE,
                'current_guests' => 0,
                'customer_name' => null,
                'waiter_user_id' => null,
            ]);
        }
    }

    /**
     * Fields to clear when a table is released back to available.
     *
     * @return array{status: string, current_guests: int|null, customer_name: null, waiter_user_id: null}
     */
    protected function restaurantTableAvailablePayload(?int $currentGuests = 0): array
    {
        return [
            'status' => PosTable::STATUS_AVAILABLE,
            'current_guests' => $currentGuests,
            'customer_name' => null,
            'waiter_user_id' => null,
        ];
    }

    /**
     * Table IDs that have at least one unpaid line already sent to production.
     *
     * @param  int[]  $tableIds
     * @return array<int, bool>
     */
    protected function restaurantTablesWithSentToProduction(array $tableIds, int $branchId, int $shiftId): array
    {
        $tableIds = array_values(array_unique(array_filter(array_map('intval', $tableIds))));
        $flags = [];
        foreach ($tableIds as $tid) {
            $flags[$tid] = false;
        }
        if ($tableIds === []) {
            return $flags;
        }

        $creatorId = Auth::user()->creatorId();
        $posQuery = Pos::whereIn('table_id', $tableIds)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->whereHas('items', function ($q) {
                $q->whereNotNull('sent_at')
                    ->whereNull('voided_at')
                    ->whereNull('removed_from_cart_at')
                    ->where('status', '!=', \App\Models\PosProduct::STATUS_CANCELLED);
            });

        if ($branchId > 0) {
            $posQuery->where('branch_id', $branchId);
        }

        $posQuery->where(function ($q) use ($shiftId) {
            $q->whereHas('shift', function ($sq) {
                $sq->where('status', \App\Models\PosShift::STATUS_OPEN);
            });
            if ($shiftId > 0) {
                $q->orWhere('shift_id', $shiftId);
            }
        });

        foreach ($posQuery->pluck('table_id') as $tid) {
            $flags[(int) $tid] = true;
        }

        return $flags;
    }

    /**
     * Map table model to layout JSON (customer, guests, cart total).
     *
     * @param  array<int, float>|null  $cartTotalsByTable
     * @param  array<int, bool>|null  $sentToProductionByTable
     */
    protected function mapRestaurantLayoutTable(Request $request, $t, int $shiftId, int $branchId = 0, ?array $cartTotalsByTable = null, ?array $sentToProductionByTable = null): array
    {
        $tableId = (int) $t->id;
        if (is_array($cartTotalsByTable) && array_key_exists($tableId, $cartTotalsByTable)) {
            $cartTotal = (float) $cartTotalsByTable[$tableId];
        } else {
            $cartTotal = $this->computeRestaurantTableCartTotal($request, $tableId, $shiftId, $branchId);
        }

        $t->loadMissing('waiterUser');
        $isAvailable = $t->status === PosTable::STATUS_AVAILABLE;
        $hasSentToProduction = is_array($sentToProductionByTable)
            ? ! empty($sentToProductionByTable[$tableId])
            : false;
        // Waiter name on cards only after the table is in use and order was sent to production.
        $showWaiter = ! $isAvailable && $hasSentToProduction && $t->waiterUser;

        return [
            'id' => $tableId,
            'name' => $t->name,
            'status' => $t->status,
            'capacity' => $t->capacity,
            'current_guests' => $t->current_guests,
            'customer_name' => $t->customer_name,
            'waiter_user_id' => $showWaiter && $t->waiter_user_id ? (int) $t->waiter_user_id : null,
            'waiter_name' => $showWaiter ? $t->waiterUser->name : null,
            'has_sent_to_production' => $hasSentToProduction,
            'cart_total' => $cartTotal,
            'cart_total_formatted' => Auth::user()->priceFormat($cartTotal),
        ];
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
        if ($resp = $this->assertRestaurantPosActorBranch((int) $branchId)) {
            return $resp;
        }
        $shiftId = (int) $request->get('shift_id', 0);
        $forManage = $request->boolean('for_manage');
        $areas = PosArea::where('created_by', Auth::user()->creatorId())
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        // Include tables for this branch OR tables whose area belongs to this branch
        $tablesQuery = PosTable::where('created_by', Auth::user()->creatorId())
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhereHas('area', function ($aq) use ($branchId) {
                        $aq->where('branch_id', $branchId);
                    });
            })
            ->with(['area', 'waiterUser']);

        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $posActor = $pinCheck;
        $posActorId = $posActor->id;
        // Waiter sees only tables assigned to their current shift (skip for Manage Tables modal)
        $isWaiterOnly = $posActor->can('pos restaurant waiter')
            && ! $posActor->can('pos restaurant full')
            && ! $posActor->can('pos restaurant manager');
        if ($isWaiterOnly && ! $forManage) {
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
        // Clear stale waiter/guest data still attached to free tables.
        $staleAvailableIds = $tables
            ->filter(fn ($t) => $t->status === PosTable::STATUS_AVAILABLE && ($t->waiter_user_id || $t->customer_name || $t->current_guests))
            ->pluck('id')
            ->all();
        if ($staleAvailableIds !== []) {
            PosTable::whereIn('id', $staleAvailableIds)->update([
                'waiter_user_id' => null,
                'customer_name' => null,
                'current_guests' => 0,
            ]);
            foreach ($tables as $t) {
                if (in_array((int) $t->id, array_map('intval', $staleAvailableIds), true)) {
                    $t->waiter_user_id = null;
                    $t->customer_name = null;
                    $t->current_guests = 0;
                    $t->unsetRelation('waiterUser');
                }
            }
        }
        $knownAreaIds = $areas->pluck('id');
        $branchIdInt = (int) $branchId;
        $tableIdList = $tables->pluck('id')->all();
        $cartTotalsByTable = $this->computeRestaurantTableCartTotalsBatch(
            $request,
            $tableIdList,
            $branchIdInt,
            $shiftId
        );
        $sentToProductionByTable = $this->restaurantTablesWithSentToProduction(
            $tableIdList,
            $branchIdInt,
            $shiftId
        );
        $result = $areas->map(function ($area) use ($tables, $request, $shiftId, $branchIdInt, $cartTotalsByTable, $sentToProductionByTable) {
            return [
                'id' => $area->id,
                'name' => $area->name,
                'tables' => $tables->where('area_id', $area->id)->values()->map(
                    fn ($t) => $this->mapRestaurantLayoutTable($request, $t, $shiftId, $branchIdInt, $cartTotalsByTable, $sentToProductionByTable)
                )->toArray(),
            ];
        })->toArray();
        // Tables without area, or with an area not in this branch's area list
        $orphanTables = $tables->filter(function ($t) use ($knownAreaIds) {
            return ! $t->area_id || ! $knownAreaIds->contains($t->area_id);
        });
        if ($orphanTables->isNotEmpty()) {
            $result[] = [
                'id' => 0,
                'name' => __('Other'),
                'tables' => $orphanTables->values()->map(
                    fn ($t) => $this->mapRestaurantLayoutTable($request, $t, $shiftId, $branchIdInt, $cartTotalsByTable, $sentToProductionByTable)
                )->toArray(),
            ];
        }
        return response()->json(['areas' => $result]);
    }

    /**
     * List open delivery/takeaway orders for branch + shift (multiple orders, like tables list).
     * Used to show customer-name buttons for delivery/takeaway; click = load that order.
     */
    public function getRestaurantDeliveryTakeawayOrders(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $branchId = (int) $request->get('branch_id');
        $shiftId = (int) $request->get('shift_id');
        $orderType = $request->get('order_type');
        if (!$branchId || !$shiftId || !in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
            return response()->json(['orders' => []]);
        }
        if ($resp = $this->assertRestaurantPosActorBranch($branchId)) {
            return $resp;
        }
        $creatorId = Auth::user()->creatorId();
        $orders = Pos::whereNull('table_id')
            ->where('order_type', $orderType)
            ->where('shift_id', $shiftId)
            ->where('branch_id', $branchId)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->whereHas('shift', fn ($q) => $q->where('status', \App\Models\PosShift::STATUS_OPEN))
            ->withCount('items')
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'guest_name' => $p->guest_name ?? '',
                'guest_email' => $p->guest_email ?? '',
                'guest_phone' => $p->guest_phone ?? '',
                'items_count' => $p->items_count ?? 0,
                'display_label' => $p->guest_name ?: ($p->guest_phone ?: __('pos.customer') . ' #' . $p->id),
            ])
            ->toArray();
        return response()->json(['orders' => $orders]);
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
        if ($resp = $this->assertRestaurantPosActorBranch($branchId)) {
            return $resp;
        }
        $posActorId = PosActorService::posActorId();
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
        if ($resp = $this->assertRestaurantPosActorBranch($branchId)) {
            return $resp;
        }
        $currentUserId = PosActorService::posActorId();
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
                    if (! $this->isActivePosBillLine($line)) {
                        continue;
                    }
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

        // Restaurant: when paying delivery/takeaway or split dine-in check, load cart from Pos (by pos_id)
        $orderType = $request->get('pos_order_type');
        $posId = (int) $request->get('pos_id');
        $tableIdForPay = (int) $request->get('pos_table_id');
        $sessEmpty = empty($sess) || !is_array($sess) || count($sess) === 0;
        if (Auth::user()->canManagePos() && $sessEmpty && $shiftId && $posId > 0) {
            $creatorId = Auth::user()->creatorId();
            $cartKey = 'pos_restaurant_cart';
            $allCarts = $request->session()->get($cartKey, []);

            $posQuery = Pos::where('id', $posId)
                ->where('shift_id', $shiftId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->with(['items.product']);

            if (in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
                $posQuery->whereNull('table_id')->where('order_type', $orderType);
            } elseif ($tableIdForPay > 0) {
                $posQuery->where('table_id', $tableIdForPay);
            }

            $pos = $posQuery->first();
            if ($pos && $pos->items->isNotEmpty()) {
                $sess = [];
                foreach ($pos->items as $line) {
                    if ($line->voided_at || $line->removed_from_cart_at) {
                        continue;
                    }
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
                $sessionCartKey = 'pos_' . $pos->id;
                if (!empty($allCarts[$sessionCartKey])) {
                    foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                        $pid = is_numeric($pid) ? (int) $pid : $pid;
                        if (isset($sess[$pid])) {
                            $sess[$pid]['quantity'] += (int) ($v['quantity'] ?? 0);
                            $sess[$pid]['subtotal'] = ($sess[$pid]['price'] * $sess[$pid]['quantity']) * (1 + ($sess[$pid]['tax'] ?? 0) / 100);
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
        } elseif (Auth::user()->canManagePos() && $sessEmpty && $shiftId && in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
            $creatorId = Auth::user()->creatorId();
            $cartKey = 'pos_restaurant_cart';
            $allCarts = $request->session()->get($cartKey, []);

            if ($posId > 0) {
                $pos = Pos::where('id', $posId)
                    ->whereNull('table_id')
                    ->where('order_type', $orderType)
                    ->where('shift_id', $shiftId)
                    ->where('created_by', $creatorId)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->with(['items.product'])
                    ->first();
                if ($pos && $pos->items->isNotEmpty()) {
                    $sess = [];
                    foreach ($pos->items as $line) {
                        if (! $this->isActivePosBillLine($line)) {
                            continue;
                        }
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
                    $sessionCartKey = 'pos_' . $pos->id;
                    if (!empty($allCarts[$sessionCartKey])) {
                        foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                            $pid = is_numeric($pid) ? (int) $pid : $pid;
                            if (isset($sess[$pid])) {
                                $sess[$pid]['quantity'] += (int) ($v['quantity'] ?? 0);
                                $sess[$pid]['subtotal'] = ($sess[$pid]['price'] * $sess[$pid]['quantity']) * (1 + ($sess[$pid]['tax'] ?? 0) / 100);
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
            } else {
                $sessionCartKey = $orderType . '_' . $shiftId;
                if (!empty($allCarts[$sessionCartKey])) {
                    $sess = [];
                    foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                        $pid = is_numeric($pid) ? (int) $pid : $pid;
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
            $costSubtotal = 0;
            $costTaxTotal = 0;
            $sales = [];
            $summaryLines = [];

            foreach ($sess as $key => $value) {
                $priceType = $value['price_type'] ?? 'exclusive';
                $lineSubtotal = (float) ($value['subtotal'] ?? 0);
                $taxRate = (float) ($value['tax'] ?? 0);
                $qty = (float) ($value['quantity'] ?? 0);
                $saleUnit = (float) ($value['price'] ?? 0);
                if ($priceType === 'inclusive' && $taxRate > 0) {
                    $tax = $lineSubtotal - ($lineSubtotal / (1 + $taxRate / 100));
                } else {
                    $subtotal = $saleUnit * $qty;
                    $tax = ($subtotal * $taxRate) / 100;
                }
                $taxTotal += $tax;
                $productId = (int) ($value['id'] ?? $key);
                $variantId = isset($value['product_variant_id']) ? (int) $value['product_variant_id'] : null;
                if ($variantId !== null && $variantId <= 0) {
                    $variantId = null;
                }
                $product = ProductService::whereId($productId)->where('created_by', $user->creatorId())->first();
                $costUnit = $this->resolveProductCostUnitPrice($product, $variantId);
                $costLineNet = $costUnit * $qty;
                $costLineTax = ($costLineNet * $taxRate) / 100;
                $costSubtotal += $costLineNet + $costLineTax;
                $costTaxTotal += $costLineTax;

                $sales['data'][$key]['name'] = $value['name'] ?? __('Product');
                $sales['data'][$key]['quantity'] = $value['quantity'];
                $sales['data'][$key]['price'] = Auth::user()->priceFormat($value['price']);
                $sales['data'][$key]['tax'] = ($value['tax'] ?? 0) . '%';
                $sales['data'][$key]['product_tax'] = $value['product_tax'] ?? '-';
                $sales['data'][$key]['tax_amount'] = Auth::user()->priceFormat($tax);
                $sales['data'][$key]['subtotal'] = Auth::user()->priceFormat($value['subtotal']);
                $mainsubtotal += $value['subtotal'];

                $summaryLines[] = [
                    'qty' => $qty,
                    'sale_unit' => round($saleUnit, 2),
                    'cost_unit' => round($costUnit, 2),
                    'tax_rate' => $taxRate,
                    'sale_line_total' => round((float) ($value['subtotal'] ?? 0), 2),
                    'cost_line_total' => round($costLineNet + $costLineTax, 2),
                ];
            }

            $discount = !empty($request->discount) ? (float) $request->discount : 0;
            $total = $mainsubtotal - $discount;
            $costTotal = max(0, $costSubtotal - $discount);
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
                'sale_subtotal_raw' => round($mainsubtotal, 2),
                'sale_tax_raw' => round($taxTotal, 2),
                'sale_total_raw' => round($total, 2),
                'cost_subtotal_raw' => round($costSubtotal, 2),
                'cost_tax_raw' => round($costTaxTotal, 2),
                'cost_total_raw' => round($costTotal, 2),
                'discount_raw' => round($discount, 2),
                'lines' => $summaryLines,
            ];

            $canGivePosCredit = Auth::user()->can('give pos credit') || Auth::user()->can('manage pos');
            $posCreditTermOptions = ['7 days' => '7 ' . __('Days'), '15 days' => '15 ' . __('Days'), '30 days' => '30 ' . __('Days'), '90 days' => '90 ' . __('Days'), '120 days' => '120 ' . __('Days')];
            return view('pos.show', compact('sales', 'details', 'orderSummary', 'canGivePosCredit', 'posCreditTermOptions'));
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
        $documentType = $this->resolvePosDocumentTypeFromRequest($request);
        $pos_id = $this->invoicePosNumber($documentType);

        if (!isset($sales) || empty($sales) || count($sales) == 0) {
            return response()->json(['code' => 404, 'success' => __('Items not found!')], 404);
        }

        foreach ($sales as $saleKey => $v) {
            $pid = (int) ($v['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $cartProduct = ProductService::where('id', $pid)->where('created_by', $user_id)->first();
            if ($cartProduct && ($cartProduct->variants_enabled ?? false)) {
                [$resolvedVid, $variantError] = $this->resolveRequestProductVariantId($cartProduct, is_array($v) ? $v : []);
                if ($variantError) {
                    return response()->json(['code' => 400, 'error' => $variantError], 400);
                }
                if ($resolvedVid) {
                    $sales[$saleKey]['product_variant_id'] = $resolvedVid;
                }
            }
            if ($cartProduct && ($cartProduct->identifier_tracking ?? false)) {
                try {
                    InvoiceSaleDeductionService::validateTrackedIdentifiersForLine(
                        $pid,
                        (float) ($v['quantity'] ?? 0),
                        $user_id,
                        $v['identifiers'] ?? null
                    );
                } catch (\Throwable $e) {
                    return response()->json(['code' => 400, 'error' => $e->getMessage()], 400);
                }
            }
            if ($cartProduct && ($cartProduct->options_enabled ?? false)) {
                $sel = ProductOptionService::normalizeSelectedOptions($v['selected_options'] ?? $v['selected_options_json'] ?? null);
                if ($sel === []) {
                    return response()->json(['code' => 400, 'error' => __('Select at least one product option.')], 400);
                }
                try {
                    ProductOptionService::validateSelectedForParent($pid, $sel, $user_id, false);
                } catch (\Throwable $e) {
                    return response()->json(['code' => 400, 'error' => $e->getMessage()], 400);
                }
            }
        }

        // Block credit sale if customer has overdue invoice(s)
        $isCreditPayment = (strtolower($request->payment_method ?? '') === 'credit')
            || (is_array($request->input('payments')) && collect($request->input('payments'))->contains(fn ($p) => strtolower($p['payment_method'] ?? '') === 'credit'));
        if ($customer_id && $isCreditPayment) {
            $overdue = (new Customer())->customerOverdue($customer_id);
            if ($overdue > 0) {
                return response()->json(['code' => 403, 'error' => __('Account blocked: customer has overdue invoice(s). Please collect payment first.')], 403);
            }
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

        // Resolve warehouse_id: required, cannot be null. Use request value if valid, else branch default / first warehouse.
        $warehouse_id = warehouse::warehouse_id($request->warehouse_name);
        if (empty($request->warehouse_name) || $warehouse_id <= 0) {
            $fallbackBranchId = (int) ($request->branch_id ?? 0);
            $defaultWarehouse = null;
            if ($fallbackBranchId > 0) {
                $branchForDefault = Branch::where('id', $fallbackBranchId)->where('created_by', $user_id)->first();
                if ($branchForDefault) {
                    $resolved = $branchForDefault->resolveDefaultWarehouseId();
                    if ($resolved) {
                        $defaultWarehouse = warehouse::find($resolved);
                    }
                }
            }
            if (!$defaultWarehouse) {
                $defaultWarehouse = warehouse::where('created_by', $user_id)->first();
            }
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

        // Only admin or users with "give pos credit" permission can use Credit payment at POS
        $paymentsInput = $request->input('payments');
        $singleMethod = $request->payment_method ?? null;
        $wantsCredit = ($singleMethod === PosPayment::METHOD_CREDIT)
            || (is_array($paymentsInput) && collect($paymentsInput)->contains(fn ($p) => ($p['payment_method'] ?? '') === PosPayment::METHOD_CREDIT));
        if ($wantsCredit && !Auth::user()->can('give pos credit') && !Auth::user()->can('manage pos')) {
            return response()->json(['code' => 403, 'error' => __('You are not allowed to give credit at POS.')], 403);
        }

        $result = DB::table('pos')->where('pos_id', $pos_id)->where('document_type', $documentType)->where('created_by', $user_id)->get();
        if (count($result) > 0) {
            return response()->json(['code' => 200, 'success' => __('Payment is already completed!')]);
        }

        $warehouse = warehouse::find($warehouse_id);
        $branch_id = ($warehouse && $warehouse->branch_id) ? $warehouse->branch_id : Branch::where('created_by', $user_id)->value('id');

        $shiftId = $request->pos_shift_id ?: null;
        if (!$shiftId && $branch_id) {
            $openShift = \App\Models\PosShift::where('branch_id', $branch_id)
                ->where('user_id', PosActorService::posActorId())
                ->where('status', \App\Models\PosShift::STATUS_OPEN)
                ->where('created_by', Auth::user()->creatorId())
                ->first();
            if ($openShift) {
                $shiftId = $openShift->id;
            }
        }

        $pos = new Pos();
        $pos->pos_id = $pos_id;
        $pos->branch_id = $branch_id;
        $pos->order_type = $request->pos_order_type ?: null;
        $pos->table_id = $request->pos_table_id ?: null;
        $pos->shift_id = $shiftId;
        $pos->customer_id = $customer_id;
        $pos->warehouse_id = $warehouse_id;
        $pos->pos_date = date('Y-m-d');
        $pos->payment_method = $request->payment_method;
        $pos->document_type = $documentType;
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
            $product_variant_id = isset($value['product_variant_id']) ? (int) $value['product_variant_id'] : null;
            if ($product_variant_id !== null && $product_variant_id <= 0) {
                $product_variant_id = null;
            }
            $product = ProductService::whereId($product_id)->where('created_by', $user_id)->first();
            $tax_id = $product ? ProductService::tax_id($product_id) : '0';
            $priceType = $value['price_type'] ?? 'exclusive';
            $taxRate = (float) ($value['tax'] ?? 0);
            $unitPrice = $this->resolvePosLineUnitPriceForDocument(
                $product,
                $pos->document_type ?? Pos::DOCUMENT_TYPE_CASH_SALE,
                (float) ($value['price'] ?? 0),
                $product_variant_id,
                $priceType,
                $taxRate
            );
            $positems = new PosProduct();
            $positems->pos_id = $pos->id;
            $positems->product_id = $product_id;
            $positems->product_variant_id = $product_variant_id;
            $positems->price = $unitPrice;
            $positems->quantity = $value['quantity'];
            $positems->tax = $tax_id;
            $positems->discount = $discount;
            $positems->identifiers = $value['identifiers'] ?? null;
            $positems->description = isset($value['description']) ? mb_substr(trim((string) $value['description']), 0, 200) : null;
            $positems->measurement_json = $value['measurement_json'] ?? null;
            $positems->selected_options_json = ProductOptionService::encodeOptions(
                ProductOptionService::normalizeSelectedOptions($value['selected_options'] ?? $value['selected_options_json'] ?? null)
            );
            $positems->save();
            $idNote = InvoiceSaleDeductionService::formatIdentifiersNote($value['identifiers'] ?? null);
            $deductByVariant = ($product->variants_enabled ?? false) && $product_variant_id;
            if (ProductOptionService::tryDeductPosLine($product, $value, $warehouse_id, $user_id, \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null), (int) $pos->id)) {
                // options only
            } elseif (($product->type ?? null) === 'kit') {
                PosKitDeductionService::deductForSale(
                    (int) $product_id,
                    (float) $value['quantity'],
                    $warehouse_id,
                    $user_id,
                    \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                    (int) $pos->id
                );
            } elseif ($deductByVariant) {
                PosRecipeDeductionService::deductForSale(
                    (int) $product_id,
                    (float) $value['quantity'],
                    $warehouse_id,
                    $user_id,
                    \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                    (int) $pos->id,
                    $idNote,
                    $product_variant_id
                );
            } elseif (($product->batch_tracking ?? false) || PosFefoDeductionService::hasLots((int) $product_id, (int) $warehouse_id, (int) $user_id)) {
                // Auto FEFO when batch tracking enabled or lots exist
                PosFefoDeductionService::deductForSale(
                    (int) $product_id,
                    (float) $value['quantity'],
                    $warehouse_id,
                    $user_id,
                    \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                    (int) $pos->id,
                    $idNote
                );
            } else {
                PosRecipeDeductionService::deductForSale(
                    (int) $product_id,
                    (float) $value['quantity'],
                    $warehouse_id,
                    $user_id,
                    \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                    (int) $pos->id,
                    $idNote,
                    $product_variant_id
                );
            }
            try {
                InvoiceSaleDeductionService::assignIdentifiersForPosSale(
                    (int) $product_id,
                    (float) $value['quantity'],
                    (int) $user_id,
                    (int) $pos->id,
                    (int) $positems->id,
                    (int) ($customer_id ?? 0),
                    $value['identifiers'] ?? null,
                    true
                );
            } catch (\Throwable $e) {
                return response()->json(['code' => 400, 'error' => $e->getMessage()], 400);
            }
        }

        $mainsubtotal = 0;
        foreach (session()->get('pos', $sales) as $key => $value) {
            $mainsubtotal += $value['subtotal'] ?? ($value['price'] * $value['quantity']);
        }
        if (($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
            $mainsubtotal = $this->applyInternalConsumptionCostToPosItems($pos, $user_id);
        }
        $totalPaid = $mainsubtotal - $discount;
        $forceCreditPayment = ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_CREDIT_SALE;
        $payments = $request->input('payments'); // optional: [{ payment_method, amount, credit_terms?, customer_id? }, ...]
        if (is_array($payments) && count($payments) > 0) {
            $firstPayment = true;
            foreach ($payments as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if ($amt <= 0) {
                    continue;
                }
                if ($forceCreditPayment || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                    $amt = $totalPaid;
                }
                $posPayment = new PosPayment();
                $posPayment->pos_id = $pos->id;
                $posPayment->branch_id = $pos->branch_id;
                $posPayment->date = $request->date ?? date('Y-m-d');
                $allowedMethods = [PosPayment::METHOD_CASH, PosPayment::METHOD_CARD, PosPayment::METHOD_CREDIT, PosPayment::METHOD_MPESA, PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'];
                $method = in_array($p['payment_method'] ?? '', $allowedMethods, true) ? $p['payment_method'] : PosPayment::METHOD_CASH;
                if ($forceCreditPayment) {
                    $method = PosPayment::METHOD_CREDIT;
                }
                $posPayment->payment_method = $method;
                $posPayment->credit_terms = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['credit_terms'] ?? $request->credit_terms ?? null) : null;
                $posPayment->customer_id = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['customer_id'] ?? $customer_id ?? null) : null;
                $posPayment->transaction_id = !empty($p['transaction_id']) ? trim($p['transaction_id']) : null;
                $posPayment->amount = $amt;
                $posPayment->discount = $firstPayment ? (float) $discount : 0;
                $posPayment->discount_amount = $amt;
                $posPayment->tip = $firstPayment ? (float) ($request->tip ?? 0) : 0;
                $posPayment->notes = $firstPayment ? ($request->notes ?: null) : null;
                $posPayment->created_by = $user_id;
                $posPayment->save();
                $firstPayment = false;
                if ($forceCreditPayment || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                    break;
                }
            }
        } else {
            $paidAmount = (float) ($request->amount ?? $totalPaid);
            if ($paidAmount <= 0 || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION || $forceCreditPayment) {
                $paidAmount = $totalPaid;
            }
            $changeAmount = ($forceCreditPayment || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION)
                ? 0
                : (float) ($request->change_amount ?? 0);
            $posPayment = new PosPayment();
            $posPayment->pos_id = $pos->id;
            $posPayment->branch_id = $pos->branch_id;
            $posPayment->date = $request->date ?? date('Y-m-d');
            $posPayment->payment_method = $forceCreditPayment
                ? PosPayment::METHOD_CREDIT
                : ($request->payment_method ?? PosPayment::METHOD_CASH);
            $posPayment->credit_terms = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->credit_terms ?? null) : null;
            $posPayment->customer_id = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->customer_id ?: $customer_id) : null;
            $posPayment->transaction_id = $request->transaction_id ?: null;
            $posPayment->amount = $paidAmount;
            $posPayment->change_amount = $changeAmount;
            $posPayment->discount = $discount;
            $posPayment->discount_amount = $totalPaid;
            $posPayment->tip = (float) ($request->tip ?? 0);
            $posPayment->notes = $request->notes ?: null;
            $posPayment->created_by = $user_id;
            $posPayment->save();
        }

        // Phase 3: Close table after payment when all checks are paid (restaurant flow)
        if ($pos->table_id) {
            $this->closeRestaurantTableIfFullyPaid((int) $pos->table_id, $user_id);
        }

        $pos->load('items');
        $skipInvoice = $this->shouldSkipPosInvoiceForHotelRoom($request, $user_id);
        $invoice = $skipInvoice ? null : app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
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
            $documentType = $this->resolvePosDocumentTypeFromRequest($request);
            $pos_id = $this->invoicePosNumber($documentType);
            $sales = $request->input('cart') ?: session()->get('pos');

            // Restaurant: pay existing table order from DB when session cart is empty
            $tableId = (int) $request->get('pos_table_id');
            $shiftId = (int) $request->get('pos_shift_id');
            $orderType = $request->get('pos_order_type');
            $posIdParam = (int) $request->get('pos_id');
            if (($tableId && $shiftId) && (empty($sales) || !is_array($sales) || count($sales) === 0)) {
                $existingPosQuery = Pos::where('created_by', $user_id)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->with(['items.product']);
                if ($posIdParam > 0) {
                    $existingPosQuery->where('id', $posIdParam)->where('table_id', $tableId)->where('shift_id', $shiftId);
                } else {
                    $existingPosQuery->where('table_id', $tableId)->where('shift_id', $shiftId);
                }
                $existingPos = $existingPosQuery->first();
                if ($existingPos && $existingPos->items->isNotEmpty()) {
                    $sales = [];
                    foreach ($existingPos->items as $line) {
                        if (! $this->isActivePosBillLine($line)) {
                            continue;
                        }
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
            // Restaurant: pay delivery/takeaway order from DB or session when cart is empty
            if ((empty($sales) || !is_array($sales) || count($sales) === 0) && $shiftId && in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
                $cartKey = 'pos_restaurant_cart';
                $allCarts = $request->session()->get($cartKey, []);
                if ($posIdParam > 0) {
                    $existingPos = Pos::where('id', $posIdParam)
                        ->whereNull('table_id')
                        ->where('order_type', $orderType)
                        ->where('shift_id', $shiftId)
                        ->where('created_by', $user_id)
                        ->whereNull('cancelled_at')
                        ->whereDoesntHave('posPayments')
                        ->with(['items.product'])
                        ->first();
                    if ($existingPos && $existingPos->items->isNotEmpty()) {
                        $sales = [];
                        foreach ($existingPos->items as $line) {
                            if (! $this->isActivePosBillLine($line)) {
                                continue;
                            }
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
                        $sessionCartKey = 'pos_' . $existingPos->id;
                        if (!empty($allCarts[$sessionCartKey])) {
                            foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                                $pid = is_numeric($pid) ? (int) $pid : $pid;
                                if (isset($sales[$pid])) {
                                    $sales[$pid]['quantity'] += (int) ($v['quantity'] ?? 0);
                                    $sales[$pid]['subtotal'] = ($sales[$pid]['price'] * $sales[$pid]['quantity']) * (1 + ($sales[$pid]['tax'] ?? 0) / 100);
                                } else {
                                    $sales[$pid] = [
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
                } else {
                    $sessionCartKey = $orderType . '_' . $shiftId;
                    if (!empty($allCarts[$sessionCartKey])) {
                        $sales = [];
                        foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                            $pid = is_numeric($pid) ? (int) $pid : $pid;
                            $sales[$pid] = [
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

            $chargeToRoom = filter_var($request->get('hotel_charge_enabled', false), FILTER_VALIDATE_BOOLEAN);
            $hotelFolio = null;
            if ($chargeToRoom) {
                if (!$request->filled('hotel_folio_id')) {
                    return response()->json(['code' => 422, 'error' => __('Please select a room folio.')], 422);
                }
                $hotelFolio = HotelFolio::where('id', (int) $request->hotel_folio_id)
                    ->where('created_by', $user_id)
                    ->where('status', 'open')
                    ->first();
                if (!$hotelFolio) {
                    return response()->json(['code' => 404, 'error' => __('Selected room folio was not found or is already closed.')], 404);
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

                // Restaurant: use existing Pos for this table+shift or delivery/takeaway+pos_id
                $useExistingPos = false;
                $existingPos = null;
                if ($tableId && $shiftId) {
                    $existingPosQuery = Pos::where('table_id', $tableId)
                        ->where('shift_id', $shiftId)
                        ->where('created_by', $user_id)
                        ->whereNull('cancelled_at')
                        ->whereDoesntHave('posPayments')
                        ->with('items');
                    if ($posIdParam > 0) {
                        $existingPosQuery->where('id', $posIdParam);
                    }
                    $existingPos = $existingPosQuery->first();
                    if ($existingPos && $existingPos->items->isNotEmpty()) {
                        $useExistingPos = true;
                    }
                }
                if (! $useExistingPos && $posIdParam > 0 && $shiftId) {
                    $existingPos = Pos::where('id', $posIdParam)
                        ->where('created_by', $user_id)
                        ->whereNull('cancelled_at')
                        ->whereDoesntHave('posPayments')
                        ->with('items')
                        ->first();
                    if ($existingPos && $existingPos->items->isNotEmpty()) {
                        $useExistingPos = true;
                    }
                }
                if (! $useExistingPos && $posIdParam > 0 && $shiftId && in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
                    $existingPos = Pos::where('id', $posIdParam)
                        ->whereNull('table_id')
                        ->where('order_type', $orderType)
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

                $result = DB::table('pos')->where('pos_id', $pos_id)->where('document_type', $documentType)->where('created_by', $user_id)->get();
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
                    $this->assignPosSerialForDocumentType($pos, $documentType);
                    $pos->save();
                    $internalConsumptionTotal = null;
                    if (($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                        $internalConsumptionTotal = $this->applyInternalConsumptionCostToPosItems($pos, $user_id);
                        $pos->load('items');
                    }
                    foreach ($pos->items as $line) {
                        if (! $this->isActivePosBillLine($line)) {
                            continue;
                        }
                        $lineProduct = ProductService::whereId($line->product_id)->where('created_by', $user_id)->first();
                        if ($lineProduct) {
                            $variantError = $this->ensurePosLineProductVariant($line, $lineProduct, true);
                            if ($variantError) {
                                return response()->json(['code' => 400, 'error' => $variantError], 400);
                            }
                        }
                        if ($lineProduct && ($lineProduct->identifier_tracking ?? false)) {
                            try {
                                InvoiceSaleDeductionService::validateTrackedIdentifiersForLine(
                                    (int) $line->product_id,
                                    (float) $line->quantity,
                                    $user_id,
                                    $line->identifiers ?? null
                                );
                            } catch (\Throwable $e) {
                                return response()->json(['code' => 400, 'error' => $e->getMessage()], 400);
                            }
                        }
                    }
                    foreach ($pos->items as $line) {
                        if (! $this->isActivePosBillLine($line)) {
                            continue;
                        }
                        $lineProduct = ProductService::whereId($line->product_id)->where('created_by', $user_id)->first();
                        if (! $lineProduct) {
                            continue;
                        }
                        $lineVariantId = $line->product_variant_id ? (int) $line->product_variant_id : null;
                        if ($lineVariantId !== null && $lineVariantId <= 0) {
                            $lineVariantId = null;
                        }
                        $lineIdNote = InvoiceSaleDeductionService::formatIdentifiersNote($line->identifiers ?? null);
                        $deductLineByVariant = ($lineProduct->variants_enabled ?? false) && $lineVariantId;
                        if (ProductOptionService::tryDeductPosLine($lineProduct, [
                            'quantity' => $line->quantity,
                            'selected_options_json' => $line->selected_options_json,
                        ], (int) $pos->warehouse_id, $user_id, \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null), (int) $pos->id)) {
                            // options only
                        } elseif (($lineProduct->type ?? null) === 'kit') {
                            PosKitDeductionService::deductForSale(
                                (int) $line->product_id,
                                (float) $line->quantity,
                                (int) $pos->warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id
                            );
                        } elseif ($deductLineByVariant) {
                            PosRecipeDeductionService::deductForSale(
                                (int) $line->product_id,
                                (float) $line->quantity,
                                (int) $pos->warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id,
                                $lineIdNote,
                                $lineVariantId
                            );
                        } elseif (($lineProduct->batch_tracking ?? false) || PosFefoDeductionService::hasLots((int) $line->product_id, (int) $pos->warehouse_id, (int) $user_id)) {
                            PosFefoDeductionService::deductForSale(
                                (int) $line->product_id,
                                (float) $line->quantity,
                                (int) $pos->warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id,
                                $lineIdNote
                            );
                        } else {
                            PosRecipeDeductionService::deductForSale(
                                (int) $line->product_id,
                                (float) $line->quantity,
                                (int) $pos->warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id,
                                $lineIdNote,
                                $lineVariantId
                            );
                        }
                        try {
                            InvoiceSaleDeductionService::assignIdentifiersForPosSale(
                                (int) $line->product_id,
                                (float) $line->quantity,
                                (int) $user_id,
                                (int) $pos->id,
                                (int) $line->id,
                                (int) ($customer_id ?? 0),
                                $line->identifiers ?? null,
                                true
                            );
                        } catch (\Throwable $e) {
                            return response()->json(['code' => 400, 'error' => $e->getMessage()], 400);
                        }
                    }
                    $mainsubtotal = 0;
                    $sessForTotal = $sales;
                    foreach ($sessForTotal as $key => $value) {
                        $mainsubtotal += $value['subtotal'] ?? (($value['price'] ?? 0) * ($value['quantity'] ?? 0));
                    }
                    if ($internalConsumptionTotal !== null) {
                        $mainsubtotal = $internalConsumptionTotal;
                    }
                    $total = $mainsubtotal - (float) $discount;
                    $payments = $request->input('payments');
                    $forceCreditPayment = ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_CREDIT_SALE;
                    if (is_array($payments) && count($payments) > 0) {
                        $firstPmt = true;
                        foreach ($payments as $p) {
                            $amt = (float) ($p['amount'] ?? 0);
                            if ($amt <= 0) continue;
                            if ($forceCreditPayment || $internalConsumptionTotal !== null) {
                                $amt = $total;
                            }
                            $posPayment = new PosPayment();
                            $posPayment->pos_id = $pos->id;
                            $posPayment->branch_id = $pos->branch_id;
                            $posPayment->date = $request->date ?? date('Y-m-d');
                            $method = !empty($p['payment_method']) ? $p['payment_method'] : PosPayment::METHOD_CASH;
                            if ($forceCreditPayment) {
                                $method = PosPayment::METHOD_CREDIT;
                            }
                            $posPayment->payment_method = $method;
                            $posPayment->credit_terms = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['credit_terms'] ?? $request->credit_terms ?? null) : null;
                            $posPayment->customer_id = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['customer_id'] ?? $customer_id ?? null) : null;
                            $posPayment->transaction_id = !empty($p['transaction_id']) ? trim($p['transaction_id']) : null;
                            $posPayment->amount = $amt;
                            $posPayment->discount = $firstPmt ? (float) $discount : 0;
                            $posPayment->discount_amount = $amt;
                            $posPayment->tip = $firstPmt ? (float) ($request->tip ?? 0) : 0;
                            $posPayment->notes = $firstPmt ? ($request->notes ?: null) : null;
                            $posPayment->created_by = $user_id;
                            $posPayment->save();
                            $firstPmt = false;
                            if ($forceCreditPayment || $internalConsumptionTotal !== null) {
                                break;
                            }
                        }
                    } else {
                        $paidAmount = (float) ($request->amount ?? $total);
                        if ($paidAmount <= 0 || $internalConsumptionTotal !== null || $forceCreditPayment) {
                            $paidAmount = $total;
                        }
                        $changeAmount = ($forceCreditPayment || $internalConsumptionTotal !== null) ? 0 : (float) ($request->change_amount ?? 0);
                        $posPayment = new PosPayment();
                        $posPayment->pos_id = $pos->id;
                        $posPayment->branch_id = $pos->branch_id;
                        $posPayment->date = $request->date ?? date('Y-m-d');
                        $posPayment->payment_method = $forceCreditPayment
                            ? PosPayment::METHOD_CREDIT
                            : ($request->payment_method ?? PosPayment::METHOD_CASH);
                        $posPayment->credit_terms = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->credit_terms ?? null) : null;
                        $posPayment->customer_id = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->customer_id ?: $customer_id) : null;
                        $posPayment->transaction_id = $request->transaction_id ?: null;
                        $posPayment->amount = $paidAmount;
                        $posPayment->change_amount = $changeAmount;
                        $posPayment->discount = $discount;
                        $posPayment->discount_amount = $total;
                        $posPayment->tip = (float) ($request->tip ?? 0);
                        $posPayment->notes = $request->notes ?: null;
                        $posPayment->created_by = $user_id;
                        $posPayment->save();
                    }
                    $pos->load('items');
                    $skipInvoice = $this->shouldSkipPosInvoiceForHotelRoom($request, $user_id);
                    $invoice = $skipInvoice ? null : app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
                    $this->attachPosChargeToHotelFolio($request, $pos, (float) $total, $user_id);
                    session()->forget('pos');
                    $cartKey = 'pos_restaurant_cart';
                    $sessionCartKeyToClear = $pos->table_id ? ($tableId . '_' . $shiftId) : ('pos_' . $pos->id);
                    $allCarts = session()->get($cartKey, []);
                    if (isset($allCarts[$sessionCartKeyToClear])) {
                        unset($allCarts[$sessionCartKeyToClear]);
                        session()->put($cartKey, $allCarts);
                    }
                    // Table becomes available after all checks on table are paid
                    if ($pos->table_id) {
                        $this->closeRestaurantTableIfFullyPaid((int) $pos->table_id, $user_id);
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

                $shiftId = $request->pos_shift_id ?: null;
                if (!$shiftId && $branch_id) {
                    $openShift = \App\Models\PosShift::where('branch_id', $branch_id)
                        ->where('user_id', PosActorService::posActorId())
                        ->where('status', \App\Models\PosShift::STATUS_OPEN)
                        ->where('created_by', Auth::user()->creatorId())
                        ->first();
                    if ($openShift) {
                        $shiftId = $openShift->id;
                    }
                }

                $pos = new Pos();
                    $pos->pos_id = $pos_id;
                    $pos->branch_id = $branch_id;
                    $pos->order_type = $request->pos_order_type ?: null;
                    $pos->table_id = $request->pos_table_id ?: null;
                    $pos->shift_id = $shiftId;
                    $pos->customer_id = $customer_id;
                    $pos->warehouse_id = $warehouse_id;
                    $pos->pos_date = date('Y-m-d');
                    $pos->payment_method = $request->payment_method;
                    $pos->document_type = $documentType;
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
                        $product_variant_id = isset($value['product_variant_id']) ? (int) $value['product_variant_id'] : null;
                        if ($product_variant_id !== null && $product_variant_id <= 0) {
                            $product_variant_id = null;
                        }
                        $product = ProductService::whereId($product_id)->where('created_by', $user_id)->first();
                        $tax_id = $product ? ProductService::tax_id($product_id) : '0';
                        $priceType = $value['price_type'] ?? 'exclusive';
                        $taxRate = (float) ($value['tax'] ?? 0);
                        $unitPrice = $this->resolvePosLineUnitPriceForDocument(
                            $product,
                            $pos->document_type ?? Pos::DOCUMENT_TYPE_CASH_SALE,
                            (float) ($value['price'] ?? 0),
                            $product_variant_id,
                            $priceType,
                            $taxRate
                        );
                        $positems = new PosProduct();
                        $positems->pos_id = $pos->id;
                        $positems->product_id = $product_id;
                        $positems->product_variant_id = $product_variant_id;
                        $positems->price = $unitPrice;
                        $positems->quantity = $value['quantity'];
                        $positems->tax = $tax_id;
                        $positems->discount = $discount;
                        $positems->identifiers = $value['identifiers'] ?? null;
                        $positems->description = isset($value['description']) ? mb_substr(trim((string) $value['description']), 0, 200) : null;
                        $positems->measurement_json = $value['measurement_json'] ?? null;
                        $positems->selected_options_json = ProductOptionService::encodeOptions(
                            ProductOptionService::normalizeSelectedOptions($value['selected_options'] ?? $value['selected_options_json'] ?? null)
                        );
                        $positems->save();
                        $idNote2 = InvoiceSaleDeductionService::formatIdentifiersNote($value['identifiers'] ?? null);
                        $deductByVariant2 = ($product->variants_enabled ?? false) && $product_variant_id;
                        if (ProductOptionService::tryDeductPosLine($product, $value, $warehouse_id, $user_id, \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null), (int) $pos->id)) {
                            // options only
                        } elseif (($product->type ?? null) === 'kit') {
                            PosKitDeductionService::deductForSale(
                                (int) $product_id,
                                (float) $value['quantity'],
                                $warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id
                            );
                        } elseif ($deductByVariant2) {
                            PosRecipeDeductionService::deductForSale(
                                (int) $product_id,
                                (float) $value['quantity'],
                                $warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id,
                                $idNote2,
                                $product_variant_id
                            );
                        } elseif (($product->batch_tracking ?? false) || PosFefoDeductionService::hasLots((int) $product_id, (int) $warehouse_id, (int) $user_id)) {
                            PosFefoDeductionService::deductForSale(
                                (int) $product_id,
                                (float) $value['quantity'],
                                $warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id,
                                $idNote2
                            );
                        } else {
                            PosRecipeDeductionService::deductForSale(
                                (int) $product_id,
                                (float) $value['quantity'],
                                $warehouse_id,
                                $user_id,
                                \Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                                (int) $pos->id,
                                $idNote2,
                                $product_variant_id
                            );
                        }
                        try {
                            InvoiceSaleDeductionService::assignIdentifiersForPosSale(
                                (int) $product_id,
                                (float) $value['quantity'],
                                (int) $user_id,
                                (int) $pos->id,
                                (int) $positems->id,
                                (int) ($customer_id ?? 0),
                                $value['identifiers'] ?? null,
                                true
                            );
                        } catch (\Throwable $e) {
                            return response()->json(['code' => 400, 'error' => $e->getMessage()], 400);
                        }
                    }

                    $mainsubtotal = 0;
                    $sess = is_array($sales) ? $sales : [];
                    foreach ($sess as $key => $value) {
                        $priceType = $value['price_type'] ?? 'exclusive';
                        $lineSubtotal = (float) ($value['subtotal'] ?? 0);
                        $taxRate = (float) ($value['tax'] ?? 0);
                        if ($priceType === 'inclusive' && $taxRate > 0) {
                            $tax = $lineSubtotal - ($lineSubtotal / (1 + $taxRate / 100));
                        } else {
                            $subtotal = ($value['price'] ?? 0) * ($value['quantity'] ?? 0);
                            $tax = ($subtotal * $taxRate) / 100;
                        }
                        $sales['data'][$key]['price'] = Auth::user()->priceFormat($value['price']);
                        $sales['data'][$key]['tax'] = $value['tax'] . '%';
                        $sales['data'][$key]['tax_amount'] = Auth::user()->priceFormat($tax);
                        $sales['data'][$key]['subtotal'] = Auth::user()->priceFormat($value['subtotal']);
                        $mainsubtotal += $value['subtotal'];
                    }
                    if (($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                        $mainsubtotal = $this->applyInternalConsumptionCostToPosItems($pos, $user_id);
                    }
                    $total = $mainsubtotal - $discount;
                    $forceCreditPayment = ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_CREDIT_SALE;
                    $payments = $request->input('payments');
                    if (is_array($payments) && count($payments) > 0) {
                        $firstPayment = true;
                        foreach ($payments as $p) {
                            $amt = (float) ($p['amount'] ?? 0);
                            if ($amt <= 0) {
                                continue;
                            }
                            if ($forceCreditPayment || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                                $amt = $total;
                            }
                            $posPayment = new PosPayment();
                            $posPayment->pos_id = $pos->id;
                            $posPayment->branch_id = $pos->branch_id;
                            $posPayment->date = $request->date;
                            $method = !empty($p['payment_method']) ? $p['payment_method'] : PosPayment::METHOD_CASH;
                            if ($forceCreditPayment) {
                                $method = PosPayment::METHOD_CREDIT;
                            }
                            $posPayment->payment_method = $method;
                            $posPayment->credit_terms = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['credit_terms'] ?? $request->credit_terms ?? null) : null;
                            $posPayment->customer_id = $posPayment->payment_method === PosPayment::METHOD_CREDIT ? ($p['customer_id'] ?? $customer_id ?? null) : null;
                            $posPayment->transaction_id = !empty($p['transaction_id']) ? trim($p['transaction_id']) : null;
                            $posPayment->amount = $amt;
                            $posPayment->discount = $firstPayment ? (float) $discount : 0;
                            $posPayment->discount_amount = $amt;
                            $posPayment->tip = $firstPayment ? (float) ($request->tip ?? 0) : 0;
                            $posPayment->notes = $firstPayment ? ($request->notes ?: null) : null;
                            $posPayment->created_by = $user_id;
                            $posPayment->save();
                            $firstPayment = false;
                            if ($forceCreditPayment || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                                break;
                            }
                        }
                    } else {
                        $paidAmount = (float) ($request->amount ?? $total);
                        if ($paidAmount <= 0 || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION || $forceCreditPayment) {
                            $paidAmount = $total;
                        }
                        $changeAmount = ($forceCreditPayment || ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION)
                            ? 0
                            : (float) ($request->change_amount ?? 0);
                        $posPayment = new PosPayment();
                        $posPayment->pos_id = $pos->id;
                        $posPayment->branch_id = $pos->branch_id;
                        $posPayment->date = $request->date ?? date('Y-m-d');
                        $posPayment->payment_method = $forceCreditPayment
                            ? PosPayment::METHOD_CREDIT
                            : ($request->payment_method ?? PosPayment::METHOD_CASH);
                        $posPayment->credit_terms = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->credit_terms ?? null) : null;
                        $posPayment->customer_id = ($posPayment->payment_method === PosPayment::METHOD_CREDIT) ? ($request->customer_id ?: $customer_id) : null;
                        $posPayment->transaction_id = $request->transaction_id ?: null;
                        $posPayment->amount = $paidAmount;
                        $posPayment->change_amount = $changeAmount;
                        $posPayment->discount = $discount;
                        $posPayment->discount_amount = $total;
                        $posPayment->tip = (float) ($request->tip ?? 0);
                        $posPayment->notes = $request->notes ?: null;
                        $posPayment->created_by = $user_id;
                        $posPayment->save();
                    }

                    // Phase 2: Create invoice and accounting entries from POS (skip when charging to hotel folio unless tenant override)
                    $pos->load('items');
                    $skipInvoice = $this->shouldSkipPosInvoiceForHotelRoom($request, $user_id);
                    $invoice = $skipInvoice ? null : app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
                    $this->attachPosChargeToHotelFolio($request, $pos, (float) $total, $user_id);

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

    protected function shouldSkipPosInvoiceForHotelRoom(Request $request, int $creatorId): bool
    {
        if (! Schema::hasTable('hotel_accounting_settings')) {
            return false;
        }
        $chargeToRoom = filter_var($request->get('hotel_charge_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $folioId = (int) $request->get('hotel_folio_id');
        if (! $chargeToRoom || $folioId <= 0) {
            return false;
        }
        $row = HotelAccountingSetting::where('created_by', $creatorId)->first();
        if ($row && $row->pos_invoice_when_charge_to_room) {
            return false;
        }

        return true;
    }

    protected function attachPosChargeToHotelFolio(Request $request, Pos $pos, float $total, int $creatorId): void
    {
        $chargeToRoom = filter_var($request->get('hotel_charge_enabled', false), FILTER_VALIDATE_BOOLEAN);
        if (!$chargeToRoom) {
            return;
        }

        $folioId = (int) $request->get('hotel_folio_id');
        if ($folioId <= 0 || $total <= 0) {
            return;
        }

        $folio = HotelFolio::where('id', $folioId)
            ->where('created_by', $creatorId)
            ->where('status', 'open')
            ->first();
        if (!$folio) {
            return;
        }

        $existing = $folio->lines()
            ->where('line_type', 'pos_charge')
            ->where('source_pos_id', $pos->id)
            ->exists();
        if ($existing) {
            return;
        }

        app(HotelPosChargeService::class)->chargeToFolio($folio, [
            'description' => 'POS charge for order #' . $pos->pos_id,
            'quantity' => 1,
            'unit_price' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'source_pos_id' => $pos->id,
        ], $creatorId);
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

    public function invoicePosNumber(?string $documentType = null, ?int $excludePosId = null)
    {
        if (! Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $documentType = Pos::normalizeDocumentType($documentType);
        $query = Pos::where('created_by', Auth::user()->creatorId())
            ->where('document_type', $documentType);
        if ($excludePosId) {
            $query->where('id', '!=', $excludePosId);
        }
        $max = (int) $query->max('pos_id');

        return $max > 0 ? $max + 1 : 1;
    }

    /**
     * Assign / reassign pos.pos_id for the given document type (separate sequences + prefixes).
     */
    protected function assignPosSerialForDocumentType(Pos $pos, string $documentType): void
    {
        $documentType = Pos::normalizeDocumentType($documentType);
        $sameType = ($pos->document_type ?? '') === $documentType && (int) ($pos->pos_id ?? 0) > 0;
        $pos->document_type = $documentType;
        if (! $sameType) {
            $pos->pos_id = $this->invoicePosNumber($documentType, $pos->exists ? (int) $pos->id : null);
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

    /**
     * Search paid POS receipts for Pay Receipts modal (by amount, customer name, POS number).
     */
    public function searchPayReceipts(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['success' => false, 'message' => __('Permission denied.')], 403);
        }
        $creatorId = Auth::user()->creatorId();
        $user = Auth::user();

        $query = Pos::where('created_by', $creatorId)
            ->whereHas('posPayments')
            ->with(['customer', 'posPayments']);

        if ($request->filled('pos_number')) {
            $raw = preg_replace('/\D/', '', $request->pos_number);
            if ($raw !== '') {
                $query->where('pos_id', (int) $raw);
            }
        }
        if ($request->filled('customer_name')) {
            $name = trim($request->customer_name);
            $query->where(function ($q) use ($name) {
                $q->whereHas('customer', function ($cq) use ($name) {
                    $cq->where('name', 'like', '%' . $name . '%');
                })->orWhere('guest_name', 'like', '%' . $name . '%');
            });
        }
        $list = $query->orderBy('id', 'desc')->limit(200)->get();

        if ($request->filled('amount') && is_numeric($request->amount)) {
            $amount = (float) $request->amount;
            $list = $list->filter(function ($pos) use ($amount) {
                return abs((float) $pos->posPayments->sum('amount') - $amount) < 0.01;
            })->values();
        }

        $data = $list->map(function ($pos) use ($user) {
            $total = (float) $pos->posPayments->sum('amount');
            $customerName = !empty($pos->guest_name) ? $pos->guest_name : ($pos->customer ? $pos->customer->name : __('pos.walkin_customer'));
            return [
                'id' => $pos->id,
                'pos_number' => $user->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                'date' => $user->dateFormat($pos->pos_date ?? $pos->created_at),
                'customer_name' => $customerName,
                'total' => $user->priceFormat($total),
                'total_raw' => $total,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
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

            // AJAX modal (barcode page) expects a form fragment only.
            // Direct /setting/pos visits need the admin layout — otherwise bare HTML shows.
            if (request()->ajax()) {
                return view('pos.setting', compact('settings'));
            }

            return view('pos.setting_page', compact('settings'));
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
        $branchScopeUser = $this->restaurantBranchScopeUser();
        $warehouses = warehouse::where('created_by', \Auth::user()->creatorId());
        $defaultWarehouseId = null;

        if ($branchId) {
            $b = Branch::where('id', $branchId)->where('created_by', \Auth::user()->creatorId())->where('is_active', true)->first();
            if ($b) {
                $mode = $b->isRestaurant() ? 'restaurant' : 'retail';
                if (! $branchScopeUser->canAccessBranchForPos((int) $branchId, $mode)) {
                    return response()->json([
                        'warehouses' => [],
                        'default_warehouse_id' => null,
                    ]);
                }
                $defaultWarehouseId = $b->resolveDefaultWarehouseId();
            }
            $warehouses->where('branch_id', $branchId);
        }

        $list = $warehouses->orderBy('name')->get()->pluck('name', 'id')->toArray();

        return response()->json([
            'warehouses' => $list,
            'default_warehouse_id' => $defaultWarehouseId,
        ]);
    }

    /**
     * Get open order for a table (dine-in) or for delivery/takeaway (no table).
     * Dine-in: pass table_id + branch_id or shift_id.
     * Delivery/Takeaway: pass order_type (delivery|takeaway) + shift_id + branch_id (no table_id).
     */
    public function getRestaurantOrder(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $tableId = (int) $request->get('table_id');
        $shiftId = (int) $request->get('shift_id');
        $branchId = (int) $request->get('branch_id');
        $orderType = $request->get('order_type');
        $posId = (int) $request->get('pos_id');
        $creatorId = Auth::user()->creatorId();
        $pos = null;
        $sessionCartKey = null; // tableId_shiftId | delivery_shiftId / takeaway_shiftId | pos_N

        if ($branchId && ($resp = $this->assertRestaurantPosActorBranch($branchId))) {
            return $resp;
        }

        if ($tableId && ($branchId || $shiftId)) {
            if ($posId > 0) {
                $pos = Pos::where('id', $posId)
                    ->where('table_id', $tableId)
                    ->where('created_by', $creatorId)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->with(['items.product.posPrinter', 'items.posPrinter', 'items.variant', 'posUser'])
                    ->first();
            }
            if (! $pos && $tableId && $branchId) {
                $pos = Pos::where('table_id', $tableId)
                    ->where('branch_id', $branchId)
                    ->where('created_by', $creatorId)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->whereHas('shift', fn ($q) => $q->where('status', \App\Models\PosShift::STATUS_OPEN))
                    ->with(['items.product.posPrinter', 'items.posPrinter', 'items.variant', 'posUser'])
                    ->first();
            }
            if (!$pos && $tableId && $shiftId) {
                $pos = Pos::where('table_id', $tableId)
                    ->where('shift_id', $shiftId)
                    ->where('created_by', $creatorId)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->with(['items.product.posPrinter', 'items.posPrinter', 'items.variant', 'posUser'])
                    ->first();
            }
            if ($pos) {
                $sessionCartKey = $tableId . '_' . ($pos->shift_id ?? $shiftId);
            } else {
                $sessionCartKey = $tableId . '_' . $shiftId;
            }
        } elseif (in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true) && $shiftId && $branchId) {
            // Explicit pos_id = load that existing order (cart key pos_N). No pos_id = "New" order (cart key delivery_shiftId / takeaway_shiftId, no DB order).
            if ($posId > 0) {
                $pos = Pos::where('id', $posId)
                    ->whereNull('table_id')
                    ->where('order_type', $orderType)
                    ->where('shift_id', $shiftId)
                    ->where('branch_id', $branchId)
                    ->where('created_by', $creatorId)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->whereHas('shift', fn ($q) => $q->where('status', \App\Models\PosShift::STATUS_OPEN))
                    ->with(['items.product.posPrinter', 'items.posPrinter', 'items.variant'])
                    ->first();
                if ($pos) {
                    $sessionCartKey = 'pos_' . $pos->id;
                }
            }
            if ($sessionCartKey === null) {
                $sessionCartKey = $orderType . '_' . $shiftId;
                // Do not load any existing pos for "New" order; $pos stays null, only session cart is used
            }
        }

        if ($sessionCartKey === null) {
            return response()->json([
                'pos_id' => null,
                'order_lines' => [],
                'session_items' => [],
                'shift_id' => $shiftId ?: null,
                'order_type' => $orderType,
                'guest_name' => null,
                'guest_email' => null,
                'guest_phone' => null,
            ]);
        }

        $orderLines = [];
        if ($pos) {
            foreach ($pos->items as $line) {
                if ($line->removed_from_cart_at) {
                    continue;
                }
                $printer = $line->posPrinter ?? ($line->product ? $line->product->posPrinter : null);
                $product = $line->product;
                $taxRate = $this->resolvePosLineTaxRate($line, $product);
                $displayName = $this->resolvePosLineDisplayName($line, $product);
                $lineNet = (float) $line->price * (int) $line->quantity - (float) $line->discount;
                $orderLines[] = [
                    'line_id' => $line->id,
                    'id' => $line->product_id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id ? (int) $line->product_variant_id : null,
                    'name' => $displayName,
                    'quantity' => (int) $line->quantity,
                    'price' => (float) $line->price,
                    'discount' => (float) $line->discount,
                    'tax' => $taxRate,
                    'subtotal' => $lineNet * (1 + $taxRate / 100),
                    'description' => $line->description ?? '',
                    'print_zone' => $line->print_zone ?? '',
                    'pos_printer_id' => $printer ? $printer->id : null,
                    'printer_name' => $printer ? $printer->name : null,
                    'status' => $line->status ?? PosProduct::STATUS_PENDING,
                    'sent_at' => $line->sent_at ? $line->sent_at->toIso8601String() : null,
                    'is_sent' => $line->sent_at !== null,
                    'voided_at' => $line->voided_at ? $line->voided_at->toIso8601String() : null,
                    'void_reason' => $line->void_reason ?? null,
                    'pro_image' => $product && $product->pro_image ? $product->pro_image : 'default.png',
                    'waiter_user_id' => $pos->pos_user_id ? (int) $pos->pos_user_id : null,
                    'waiter_name' => $pos->posUser ? $pos->posUser->name : null,
                    'selected_options_json' => $line->selected_options_json ?? null,
                    'selected_options' => ! empty($line->selected_options_json)
                        ? ProductOptionService::normalizeSelectedOptions($line->selected_options_json)
                        : [],
                ];
            }
        }
        $cartKey = 'pos_restaurant_cart';
        $allCarts = $request->session()->get($cartKey, []);
        $sessionItems = isset($allCarts[$sessionCartKey]) ? array_values($allCarts[$sessionCartKey]) : [];
        $productIds = array_unique(array_filter(array_merge(array_column($orderLines, 'id'), array_column($sessionItems, 'id'))));
        $productsWithPrinter = $productIds ? ProductService::whereIn('id', $productIds)->where('created_by', $creatorId)->with(['posPrinter', 'category'])->get()->keyBy('id') : collect();
        foreach ($orderLines as &$line) {
            if (isset($productsWithPrinter[$line['id']])) {
                $product = $productsWithPrinter[$line['id']];
                if (empty(trim((string) ($line['name'] ?? ''))) || ($line['name'] ?? '') === __('Product') . ' #' . $line['id']) {
                    $line['name'] = $product->name ?: ($line['name'] ?? __('Product'));
                }
                if (empty($line['print_zone'])) {
                    $line['print_zone'] = PosPrintRoutingService::resolvePrintZoneForProduct($product);
                }
                if (empty($line['pos_printer_id'])) {
                    $pr = $product->posPrinter;
                    $printerId = PosPrintRoutingService::resolvePosPrinterIdForProduct($product);
                    if ($printerId) {
                        $pr = \App\Models\PosPrinter::find($printerId);
                    }
                    $line['pos_printer_id'] = $pr ? $pr->id : null;
                    $line['printer_name'] = $pr ? $pr->name : null;
                }
            } elseif (empty(trim((string) ($line['name'] ?? '')))) {
                $line['name'] = __('Product') . ' #' . ($line['id'] ?? '');
            }
        }
        unset($line);
        foreach ($sessionItems as &$item) {
            $item = (array) $item;
            if (isset($productsWithPrinter[$item['id'] ?? 0])) {
                $product = $productsWithPrinter[$item['id']];
                if (empty(trim((string) ($item['name'] ?? '')))) {
                    $item['name'] = $product->name ?: (__('Product') . ' #' . $item['id']);
                }
                $item['print_zone'] = PosPrintRoutingService::resolvePrintZoneForProduct($product);
                $printerId = PosPrintRoutingService::resolvePosPrinterIdForProduct($product);
                $pr = $printerId ? \App\Models\PosPrinter::find($printerId) : $product->posPrinter;
                $item['pos_printer_id'] = $pr ? $pr->id : null;
                $item['printer_name'] = $pr ? $pr->name : null;
            } else {
                if (empty(trim((string) ($item['name'] ?? '')))) {
                    $item['name'] = __('Product') . ' #' . ($item['id'] ?? '');
                }
                $item['print_zone'] = $item['print_zone'] ?? '';
                $item['pos_printer_id'] = null;
                $item['printer_name'] = null;
            }
        }
        unset($item);
        $sessionItems = array_values($sessionItems);

        $checks = [];
        if ($tableId && ($branchId || $shiftId)) {
            $checkShiftId = $pos ? ($pos->shift_id ?? $shiftId) : $shiftId;
            $openChecks = Pos::where('table_id', $tableId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->when($checkShiftId, fn ($q) => $q->where('shift_id', $checkShiftId))
                ->with('items')
                ->get();
            foreach ($openChecks as $check) {
                $checkTotal = 0.0;
                foreach ($check->items as $line) {
                    if ($line->voided_at || $line->removed_from_cart_at) {
                        continue;
                    }
                    $checkTotal += (float) ($line->price * $line->quantity - $line->discount);
                }
                $checks[] = [
                    'pos_id' => $check->id,
                    'label' => $check->guest_name ?: ('Check #' . $check->pos_id),
                    'total' => $checkTotal,
                    'total_formatted' => Auth::user()->priceFormat($checkTotal),
                    'line_count' => $check->items->whereNull('voided_at')->whereNull('removed_from_cart_at')->count(),
                ];
            }
        }

        $payload = [
            'pos_id' => $pos ? $pos->id : null,
            'shift_id' => $pos ? $pos->shift_id : $shiftId,
            'order_type' => $pos ? ($pos->order_type ?? null) : $orderType,
            'session_cart_key' => $sessionCartKey,
            'order_lines' => $orderLines,
            'session_items' => $sessionItems,
            'checks' => $checks,
            'waiter_user_id' => null,
            'waiter_name' => null,
        ];
        if ($tableId) {
            $tableMeta = PosTable::with('waiterUser')->where('id', $tableId)->where('created_by', $creatorId)->first();
            if ($tableMeta) {
                $payload['waiter_user_id'] = $tableMeta->waiter_user_id ? (int) $tableMeta->waiter_user_id : null;
                $payload['waiter_name'] = $tableMeta->waiterUser ? $tableMeta->waiterUser->name : null;
                $payload['customer_name'] = $tableMeta->customer_name;
            }
        }
        if ($pos && empty($payload['waiter_name']) && $pos->posUser) {
            $payload['waiter_user_id'] = (int) $pos->pos_user_id;
            $payload['waiter_name'] = $pos->posUser->name;
        } elseif ($pos && empty($payload['waiter_name']) && $pos->pos_user_id) {
            $posUser = User::find($pos->pos_user_id);
            $payload['waiter_user_id'] = $pos->pos_user_id ? (int) $pos->pos_user_id : null;
            $payload['waiter_name'] = $posUser ? $posUser->name : null;
        }
        if ($pos && in_array($pos->order_type, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
            $payload['guest_name'] = $pos->guest_name ?? null;
            $payload['guest_email'] = $pos->guest_email ?? null;
            $payload['guest_phone'] = $pos->guest_phone ?? null;
        } else {
            $payload['guest_name'] = null;
            $payload['guest_email'] = null;
            $payload['guest_phone'] = null;
        }
        return response()->json($payload);
    }

    /**
     * Phase 1: Send to production – save order + lines (no payment, no stock deduction).
     * Dine-in: table_id required. Delivery/Takeaway: table_id null, guest_name, guest_email, guest_phone required.
     */
    public function sendToProduction(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $creatorId = Auth::user()->creatorId();
        if ($msg = BusinessDayLockService::assertNotLocked($creatorId, date('Y-m-d'))) {
            return response()->json(['error' => $msg], 422);
        }

        $orderType = $request->get('order_type');
        $allowedOrderTypes = [Pos::ORDER_TYPE_DINE_IN, Pos::ORDER_TYPE_TAKEAWAY, Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_KIOSK];
        if (!$orderType || !in_array($orderType, $allowedOrderTypes, true)) {
            $orderType = Pos::ORDER_TYPE_DINE_IN;
        }
        $isNonDineIn = in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true);

        $rules = [
            'branch_id' => 'required|exists:branches,id',
            'shift_id' => 'required|exists:pos_shifts,id',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:product_services,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'prep_minutes' => 'nullable|integer|min:1|max:480',
        ];
        if ($isNonDineIn) {
            if ((int) $request->get('pos_id', 0) > 0) {
                $rules['guest_name'] = 'nullable|string|max:255';
                $rules['guest_email'] = 'nullable|email';
                $rules['guest_phone'] = 'nullable|string|max:64';
            } else {
                $rules['guest_name'] = 'required|string|max:255';
                $rules['guest_email'] = 'required|email';
                $rules['guest_phone'] = 'required|string|max:64';
            }
        } else {
            $rules['table_id'] = 'required|exists:pos_tables,id';
        }
        $request->validate($rules);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;
        $tableId = $isNonDineIn ? null : (int) $request->table_id;
        $shiftId = (int) $request->shift_id;
        $posId = $isNonDineIn ? (int) $request->get('pos_id', 0) : 0;

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (!$branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }
        $pinActor = $pinCheck;
        if (! $pinActor->canAccessBranchForPos($branchId, 'restaurant')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $warehouse = warehouse::where('created_by', $creatorId)->where('branch_id', $branchId)->first();
        if (!$warehouse) {
            $warehouse = warehouse::where('created_by', $creatorId)->first();
        }
        if (!$warehouse) {
            return response()->json(['error' => __('Please create or select a warehouse.')], 422);
        }

        $pos = null;
        if ($isNonDineIn && $posId > 0) {
            $pos = Pos::where('id', $posId)
                ->whereNull('table_id')
                ->where('order_type', $orderType)
                ->where('shift_id', $shiftId)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->first();
        }
        if (!$pos && !$isNonDineIn) {
            // Dine-in: find existing open order for this table
            $posQuery = Pos::where('shift_id', $shiftId)
                ->where('branch_id', $branchId)
                ->where('created_by', $creatorId)
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->where('table_id', $tableId);
            $pos = $posQuery->first();
        }
        // Delivery/takeaway without pos_id = always create new Pos (do not find first open order)

        if (!$pos) {
            $pos = new Pos();
            $pos->document_type = Pos::DOCUMENT_TYPE_CASH_SALE;
            $pos->pos_id = $this->invoicePosNumber(Pos::DOCUMENT_TYPE_CASH_SALE);
            $pos->branch_id = $branchId;
            $pos->order_type = $orderType;
            $pos->table_id = $tableId;
            $pos->shift_id = $shiftId;
            $pos->customer_id = 0;
            $pos->warehouse_id = $warehouse->id;
            $pos->pos_date = date('Y-m-d');
            $pos->payment_method = null;
            $pos->created_by = $creatorId;
            $waiterUserId = PosActorService::posActorId();
            if (! $isNonDineIn && $tableId) {
                $openedTable = PosTable::where('id', $tableId)->where('created_by', $creatorId)->first();
                if ($openedTable && $openedTable->waiter_user_id) {
                    $waiterUserId = (int) $openedTable->waiter_user_id;
                }
            }
            $pos->pos_user_id = $waiterUserId;
            if ($isNonDineIn) {
                $pos->guest_name = $request->guest_name;
                $pos->guest_email = $request->guest_email;
                $pos->guest_phone = $request->guest_phone;
            }
            $pos->save();
        } else {
            $pos->order_type = $orderType;
            if ($isNonDineIn) {
                $pos->guest_name = $request->filled('guest_name') ? $request->guest_name : $pos->guest_name;
                $pos->guest_email = $request->filled('guest_email') ? $request->guest_email : $pos->guest_email;
                $pos->guest_phone = $request->filled('guest_phone') ? $request->guest_phone : $pos->guest_phone;
            }
            // Keep / sync waiter from opened table when sending more items
            if (! $isNonDineIn && $tableId && ! $pos->pos_user_id) {
                $openedTable = PosTable::where('id', $tableId)->where('created_by', $creatorId)->first();
                if ($openedTable && $openedTable->waiter_user_id) {
                    $pos->pos_user_id = (int) $openedTable->waiter_user_id;
                } else {
                    $pos->pos_user_id = PosActorService::posActorId();
                }
            }
            $pos->save();
        }

        if ($isNonDineIn && (! $pos->guest_name || ! $pos->guest_email || ! $pos->guest_phone)) {
            return response()->json(['error' => __('Customer details are required for delivery/takeaway orders.')], 422);
        }

        // Add only new lines (do not delete or change already-sent lines). Request items = unsent items to send.
        $printLines = [];
        $now = now();
        $defaultPrep = (int) (Utility::getValByName('pos_default_prep_minutes') ?: 15);
        if ($defaultPrep < 1) {
            $defaultPrep = 15;
        }
        $prepMinutes = (int) ($request->input('prep_minutes') ?: $defaultPrep);
        if ($prepMinutes < 1) {
            $prepMinutes = $defaultPrep;
        }
        $itemProductIds = collect($request->items)->pluck('id')->filter()->unique()->values()->all();
        $productsById = $itemProductIds
            ? ProductService::whereIn('id', $itemProductIds)->where('created_by', $creatorId)->with('category')->get()->keyBy('id')
            : collect();
        foreach ($request->items as $item) {
            $product = $productsById->get($item['id']);
            if (!$product) {
                continue;
            }
            $printZone = PosPrintRoutingService::resolvePrintZoneForProduct($product);
            $resolvedPrinterId = PosPrintRoutingService::resolvePosPrinterIdForProduct($product);
            $taxId = is_string($product->tax_id ?? null) ? $product->tax_id : ($product->tax_id ?? '0');
            [$variantId, $variantError] = $this->resolveRequestProductVariantId($product, is_array($item) ? $item : []);
            if ($variantError) {
                return response()->json(['error' => $variantError], 422);
            }
            $linePrep = isset($item['prep_minutes']) ? (int) $item['prep_minutes'] : $prepMinutes;
            if ($linePrep < 1) {
                $linePrep = $prepMinutes;
            }
            $line = PosProduct::create([
                'pos_id' => $pos->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity' => (int) $item['quantity'],
                'price' => (float) $item['price'],
                'tax' => $taxId,
                'discount' => (float) ($item['discount'] ?? 0),
                'description' => $item['description'] ?? null,
                'selected_options_json' => ! empty($item['selected_options'])
                    ? ProductOptionService::encodeOptions(ProductOptionService::normalizeSelectedOptions($item['selected_options']))
                    : (! empty($item['selected_options_json']) ? $item['selected_options_json'] : null),
                'status' => PosProduct::STATUS_PENDING,
                'sent_at' => $now,
                'prep_minutes' => $linePrep,
                'print_zone' => $printZone,
                'pos_printer_id' => $resolvedPrinterId,
            ]);
            $selectedOpts = ProductOptionService::normalizeSelectedOptions(
                $item['selected_options'] ?? $item['selected_options_json'] ?? $line->selected_options_json
            );
            // Fallback: restaurant session cart may hold options if the POS row lost data-selected-options.
            if ($selectedOpts === []) {
                $cartKey = 'pos_restaurant_cart';
                $allCarts = $request->session()->get($cartKey, []);
                $sessionKeys = [];
                if ($tableId && $shiftId) {
                    $sessionKeys[] = $tableId . '_' . $shiftId;
                }
                if ($orderType && $shiftId) {
                    $sessionKeys[] = $orderType . '_' . $shiftId;
                }
                if ($pos && $pos->id) {
                    $sessionKeys[] = 'pos_' . $pos->id;
                }
                foreach (array_unique($sessionKeys) as $sk) {
                    $sessCart = $allCarts[$sk] ?? [];
                    foreach ($sessCart as $sessLine) {
                        $sessLine = (array) $sessLine;
                        if ((int) ($sessLine['id'] ?? 0) !== (int) $product->id) {
                            continue;
                        }
                        $fromSess = ProductOptionService::normalizeSelectedOptions(
                            $sessLine['selected_options'] ?? $sessLine['selected_options_json'] ?? null
                        );
                        if ($fromSess !== []) {
                            $selectedOpts = $fromSess;
                            if (empty($line->selected_options_json)) {
                                $line->selected_options_json = ProductOptionService::encodeOptions($selectedOpts);
                                $line->save();
                            }
                            break 2;
                        }
                    }
                }
            }
            $details = PosPrinterAgentService::buildLineProductDetails(
                $product,
                $variantId ? (int) $variantId : null,
                $selectedOpts,
                (string) ($line->description ?? ''),
                (float) $line->price,
                (int) $line->quantity
            );
            $printLines[] = array_merge($details, [
                'line_id' => (int) $line->id,
                'print_zone' => (string) $printZone,
                'pos_printer_id' => $resolvedPrinterId ? (int) $resolvedPrinterId : null,
            ]);
        }

        // Clear all related session cart keys so ghost unsent lines cannot be re-sent/reprinted.
        $cartKey = 'pos_restaurant_cart';
        $allCarts = $request->session()->get($cartKey, []);
        $keysToClear = [];
        if ($tableId && $shiftId) {
            $keysToClear[] = $tableId . '_' . $shiftId;
        }
        if ($orderType && $shiftId) {
            $keysToClear[] = $orderType . '_' . $shiftId;
        }
        if ($pos && $pos->id) {
            $keysToClear[] = 'pos_' . $pos->id;
        }
        $cleared = false;
        foreach (array_unique($keysToClear) as $key) {
            if (isset($allCarts[$key])) {
                unset($allCarts[$key]);
                $cleared = true;
            }
        }
        if ($cleared) {
            $request->session()->put($cartKey, $allCarts);
        }

        \App\Services\PosAuditLogService::log(
            PosAuditLog::ACTION_SEND_ORDER,
            'pos',
            (int) $pos->id,
            __('Order sent to production'),
            ['table_id' => $pos->table_id, 'order_type' => $pos->order_type, 'items_count' => count($request->items), 'prep_minutes' => $prepMinutes]
        );

        $browserPrintJobs = PosPrintRoutingService::buildProductionPrintJobs($branchId, $creatorId, $printLines);

        // Additive agent queue (does not replace browser print_jobs fallback).
        try {
            PosPrinterAgentService::enqueueProductionJobs(
                $branchId,
                $creatorId,
                (int) $pos->id,
                $printLines,
                PosPrinterAgentService::buildOrderHeader($pos)
            );
        } catch (\Throwable $e) {
            \Log::warning('POS printer agent enqueue failed: ' . $e->getMessage(), [
                'pos_id' => $pos->id,
                'branch_id' => $branchId,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('Order sent to production.'),
            'pos_id' => $pos->id,
            'prep_minutes' => $prepMinutes,
            'print_jobs' => $browserPrintJobs,
        ]);
    }

    /**
     * @deprecated Use PosPrintRoutingService::buildProductionPrintJobs()
     */
    protected function buildProductionPrintJobs(int $branchId, array $lines): array
    {
        return PosPrintRoutingService::buildProductionPrintJobs($branchId, Auth::user()->creatorId(), $lines);
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
        DocumentVoidLog::create([
            'created_by' => Auth::user()->creatorId(),
            'user_id' => Auth::id(),
            'document_type' => DocumentVoidLog::TYPE_POS,
            'document_id' => $pos->id,
            'document_number' => Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
            'reason' => $request->input('reason'),
            'stock_restored' => false,
            'accounting_note_created' => false,
        ]);
        return response()->json(['success' => true, 'message' => __('Order cancelled.')]);
    }

    /**
     * Void a sent line. Managers/Admins void directly; Cashier/Waiter require Manager/Admin/POS password.
     * Reason is mandatory; void appears in shift report.
     */
    public function voidRestaurantLine(Request $request, $line)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $actor = $pinCheck;
        if (! PosAuditLogService::authorizeVoidRestaurantLine($request, $actor)) {
            if (PosAuditLogService::canVoidRestaurantLine($actor) && ! PosAuditLogService::canVoidRestaurantLineDirectly($actor)) {
                return response()->json(['error' => __('pos.void_password_invalid')], 403);
            }

            return response()->json(['error' => __('Only Manager can void items.')], 403);
        }

        $request->validate(['reason' => 'required|string|max:500']);
        $posProduct = PosProduct::where('id', $line)->whereHas('pos', function ($q) use ($actor) {
            $q->where('created_by', $actor->creatorId());
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
        $posProduct->voided_by = $actor->id;
        $posProduct->save();
        PosAuditLogService::log(
            PosAuditLog::ACTION_VOID_LINE,
            'pos_product',
            (int) $posProduct->id,
            __('Line voided: :reason', ['reason' => $request->reason]),
            ['pos_id' => $pos->id, 'line_id' => $posProduct->id]
        );
        DocumentVoidService::logPosLineVoid(
            (int) $posProduct->id,
            (int) $pos->id,
            Auth::user()->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
            $request->reason
        );

        return response()->json(['success' => true, 'message' => __('Line voided.')]);
    }

    /**
     * Admin/company admin: remove sent lines from the restaurant cart (hidden from POS, audit logged).
     */
    public function deleteRestaurantLines(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $actor = $pinCheck;
        if (! PosAuditLogService::canDeleteSentRestaurantLines($actor)) {
            return response()->json(['error' => __('Only Admin / Company Admin can delete sent items.')], 403);
        }

        $request->validate([
            'line_ids' => 'required|array|min:1',
            'line_ids.*' => 'integer',
        ]);

        $lineIds = array_values(array_unique(array_map('intval', $request->input('line_ids', []))));
        $lines = PosProduct::whereIn('id', $lineIds)
            ->whereHas('pos', function ($q) use ($actor) {
                $q->where('created_by', $actor->creatorId());
            })
            ->with(['pos', 'product'])
            ->get();

        if ($lines->isEmpty()) {
            return response()->json(['error' => __('No deletable lines found.')], 404);
        }

        $deleted = 0;
        foreach ($lines as $posProduct) {
            $pos = $posProduct->pos;
            if (! $pos || $pos->posPayments()->exists()) {
                continue;
            }
            if (! $posProduct->sent_at) {
                continue;
            }
            if ($posProduct->voided_at) {
                continue;
            }
            if ($posProduct->removed_from_cart_at) {
                continue;
            }

            $posProduct->removed_from_cart_at = now();
            $posProduct->removed_from_cart_by = $actor->id;
            $posProduct->save();

            $productName = $posProduct->product ? $posProduct->product->name : (__('Product') . ' #' . $posProduct->product_id);
            PosAuditLogService::log(
                PosAuditLog::ACTION_DELETE_LINE,
                'pos_product',
                (int) $posProduct->id,
                __('Item deleted from cart: :product', ['product' => $productName]),
                [
                    'pos_id' => $pos->id,
                    'line_id' => $posProduct->id,
                    'product_id' => $posProduct->product_id,
                    'product_name' => $productName,
                    'quantity' => (int) $posProduct->quantity,
                ]
            );
            $deleted++;
        }

        if ($deleted === 0) {
            return response()->json(['error' => __('No deletable sent items were selected.')], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('Selected items deleted from cart.'),
            'deleted' => $deleted,
        ]);
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
        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
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
        if ($resp = $this->assertRestaurantPosActorBranch($branchId)) {
            return $resp;
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
            $targetPos->document_type = Pos::DOCUMENT_TYPE_CASH_SALE;
            $targetPos->pos_id = $this->invoicePosNumber(Pos::DOCUMENT_TYPE_CASH_SALE);
            $targetPos->branch_id = $branchId;
            $targetPos->order_type = Pos::ORDER_TYPE_DINE_IN;
            $targetPos->table_id = $targetTableId;
            $targetPos->shift_id = $targetShiftId;
            $targetPos->customer_id = 0;
            $targetPos->warehouse_id = $warehouse->id;
            $targetPos->pos_date = date('Y-m-d');
            $targetPos->payment_method = null;
            $targetPos->created_by = $creatorId;
            $targetPos->pos_user_id = PosActorService::posActorId();
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
            if ($sourcePos && $sourcePos->id !== $targetPos->id) {
                foreach ($sourcePos->items as $item) {
                    $item->pos_id = $targetPos->id;
                    $item->save();
                    $movedCount++;
                }
                $sourcePos->cancelled_at = now();
                $sourcePos->save();
            }
            // Always release joined source tables (clears waiter/customer/guests).
            \App\Models\PosTable::where('id', $srcTableId)->where('created_by', $creatorId)->update($this->restaurantTableAvailablePayload());
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

    /**
     * Move table: transfer the current table's order (and session cart) to another table.
     * Source table is released; target receives all items and guest info.
     */
    public function moveRestaurantTable(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'source_table_id' => 'required|exists:pos_tables,id',
            'target_table_id' => 'required|exists:pos_tables,id|different:source_table_id',
            'shift_id' => 'required|exists:pos_shifts,id',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;
        $sourceTableId = (int) $request->source_table_id;
        $targetTableId = (int) $request->target_table_id;
        $shiftId = (int) $request->shift_id;

        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->first();
        if (! $branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }
        if ($resp = $this->assertRestaurantPosActorBranch($branchId)) {
            return $resp;
        }

        $sourceTable = \App\Models\PosTable::where('id', $sourceTableId)->where('created_by', $creatorId)->first();
        $targetTable = \App\Models\PosTable::where('id', $targetTableId)->where('created_by', $creatorId)->first();
        if (! $sourceTable || ! $targetTable) {
            return response()->json(['error' => __('Table not found.')], 422);
        }
        if ($targetTable->status !== \App\Models\PosTable::STATUS_AVAILABLE) {
            return response()->json(['error' => __('pos.move_target_must_be_available')], 422);
        }

        $warehouse = warehouse::where('created_by', $creatorId)->where('branch_id', $branchId)->first()
            ?: warehouse::where('created_by', $creatorId)->first();
        if (! $warehouse) {
            return response()->json(['error' => __('Please create or select a warehouse.')], 422);
        }

        $openShiftIds = \App\Models\PosShift::where('branch_id', $branchId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', $creatorId)
            ->pluck('id');

        $targetPos = Pos::where('table_id', $targetTableId)
            ->where('branch_id', $branchId)
            ->whereIn('shift_id', $openShiftIds)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->first();

        if (! $targetPos) {
            $targetPos = new Pos();
            $targetPos->document_type = Pos::DOCUMENT_TYPE_CASH_SALE;
            $targetPos->pos_id = $this->invoicePosNumber(Pos::DOCUMENT_TYPE_CASH_SALE);
            $targetPos->branch_id = $branchId;
            $targetPos->order_type = Pos::ORDER_TYPE_DINE_IN;
            $targetPos->table_id = $targetTableId;
            $targetPos->shift_id = $shiftId;
            $targetPos->customer_id = 0;
            $targetPos->warehouse_id = $warehouse->id;
            $targetPos->pos_date = date('Y-m-d');
            $targetPos->payment_method = null;
            $targetPos->created_by = $creatorId;
            $targetPos->pos_user_id = PosActorService::posActorId();
            $targetPos->save();
        }

        $movedCount = 0;
        $sourcePosList = Pos::where('table_id', $sourceTableId)
            ->where('branch_id', $branchId)
            ->whereIn('shift_id', $openShiftIds)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->with('items')
            ->get();

        foreach ($sourcePosList as $sourcePos) {
            if ($sourcePos->id === $targetPos->id) {
                continue;
            }
            foreach ($sourcePos->items as $item) {
                $item->pos_id = $targetPos->id;
                $item->save();
                $movedCount++;
            }
            $sourcePos->cancelled_at = now();
            $sourcePos->save();
        }

        $cartKey = 'pos_restaurant_cart';
        $allCarts = $request->session()->get($cartKey, []);
        $sourceCartKey = $sourceTableId . '_' . $shiftId;
        $targetCartKey = $targetTableId . '_' . $shiftId;
        if (! empty($allCarts[$sourceCartKey])) {
            if (empty($allCarts[$targetCartKey])) {
                $allCarts[$targetCartKey] = $allCarts[$sourceCartKey];
            } else {
                foreach ($allCarts[$sourceCartKey] as $pid => $item) {
                    if (isset($allCarts[$targetCartKey][$pid])) {
                        $existing = (array) $allCarts[$targetCartKey][$pid];
                        $incoming = (array) $item;
                        $existingQty = (int) ($existing['quantity'] ?? 1);
                        $incomingQty = (int) ($incoming['quantity'] ?? 1);
                        $existing['quantity'] = $existingQty + $incomingQty;
                        $existing['subtotal'] = (float) ($existing['subtotal'] ?? 0) + (float) ($incoming['subtotal'] ?? 0);
                        $allCarts[$targetCartKey][$pid] = $existing;
                    } else {
                        $allCarts[$targetCartKey][$pid] = $item;
                    }
                }
            }
            unset($allCarts[$sourceCartKey]);
        }
        foreach ($openShiftIds as $sid) {
            $legacyKey = $sourceTableId . '_' . $sid;
            if ($legacyKey !== $sourceCartKey && isset($allCarts[$legacyKey])) {
                unset($allCarts[$legacyKey]);
            }
        }
        $request->session()->put($cartKey, $allCarts);

        $mergedGuests = (int) ($sourceTable->current_guests ?? 0) + (int) ($targetTable->current_guests ?? 0);
        $targetTable->update([
            'status' => \App\Models\PosTable::STATUS_OCCUPIED,
            'current_guests' => $mergedGuests > 0 ? $mergedGuests : (int) ($sourceTable->current_guests ?? 0),
            'customer_name' => $sourceTable->customer_name ?: $targetTable->customer_name,
            'waiter_user_id' => $sourceTable->waiter_user_id ?: $targetTable->waiter_user_id,
        ]);
        $sourceTable->update($this->restaurantTableAvailablePayload());

        PosAuditLogService::log(
            PosAuditLog::ACTION_SEND_ORDER,
            'pos',
            (int) $targetPos->id,
            __('Table moved'),
            [
                'source_table_id' => $sourceTableId,
                'target_table_id' => $targetTableId,
                'items_moved' => $movedCount,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => __('pos.table_moved_success'),
            'pos_id' => $targetPos->id,
            'target_table_id' => $targetTableId,
            'target_table_name' => $targetTable->name,
        ]);
    }

    /**
     * Split bill by items: create separate checks (Pos records) per guest group.
     */
    public function splitRestaurantOrderByItems(Request $request)
    {
        if (! Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $pinCheck = $this->restaurantPinActorOrJsonError();
        if ($pinCheck instanceof \Illuminate\Http\JsonResponse) {
            return $pinCheck;
        }
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'table_id' => 'required|exists:pos_tables,id',
            'shift_id' => 'required|exists:pos_shifts,id',
            'splits' => 'required|array|min:2',
            'splits.*.label' => 'required|string|max:120',
            'splits.*.line_ids' => 'required|array|min:1',
            'splits.*.line_ids.*' => 'integer|exists:pos_products,id',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = (int) $request->branch_id;
        $tableId = (int) $request->table_id;
        $shiftId = (int) $request->shift_id;

        if ($resp = $this->assertRestaurantPosActorBranch($branchId)) {
            return $resp;
        }

        $warehouse = warehouse::where('created_by', $creatorId)->where('branch_id', $branchId)->first()
            ?: warehouse::where('created_by', $creatorId)->first();
        if (! $warehouse) {
            return response()->json(['error' => __('Please create or select a warehouse.')], 422);
        }

        $allLineIds = [];
        foreach ($request->splits as $split) {
            foreach ($split['line_ids'] as $lineId) {
                $lineId = (int) $lineId;
                if (in_array($lineId, $allLineIds, true)) {
                    return response()->json(['error' => __('pos.split_line_assigned_twice')], 422);
                }
                $allLineIds[] = $lineId;
            }
        }

        $sourcePosList = Pos::where('table_id', $tableId)
            ->where('branch_id', $branchId)
            ->where('shift_id', $shiftId)
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereDoesntHave('posPayments')
            ->with('items')
            ->get();

        if ($sourcePosList->isEmpty()) {
            return response()->json(['error' => __('pos.split_requires_sent_items')], 422);
        }

        $linePosMap = [];
        foreach ($sourcePosList as $pos) {
                foreach ($pos->items as $line) {
                if ($line->voided_at || $line->removed_from_cart_at) {
                    continue;
                }
                $linePosMap[(int) $line->id] = $pos;
            }
        }

        foreach ($allLineIds as $lineId) {
            if (! isset($linePosMap[$lineId])) {
                return response()->json(['error' => __('pos.split_invalid_line')], 422);
            }
        }

        $parentPos = $sourcePosList->first();
        $createdChecks = [];

        foreach ($request->splits as $split) {
            $label = trim((string) $split['label']);
            $lineIds = array_map('intval', $split['line_ids']);

            $checkPos = new Pos();
            $checkPos->document_type = Pos::DOCUMENT_TYPE_CASH_SALE;
            $checkPos->pos_id = $this->invoicePosNumber(Pos::DOCUMENT_TYPE_CASH_SALE);
            $checkPos->branch_id = $branchId;
            $checkPos->order_type = Pos::ORDER_TYPE_DINE_IN;
            $checkPos->table_id = $tableId;
            $checkPos->parent_pos_id = $parentPos->id;
            $checkPos->shift_id = $shiftId;
            $checkPos->customer_id = 0;
            $checkPos->guest_name = $label;
            $checkPos->warehouse_id = $warehouse->id;
            $checkPos->pos_date = date('Y-m-d');
            $checkPos->payment_method = null;
            $checkPos->created_by = $creatorId;
            $checkPos->pos_user_id = PosActorService::posActorId();
            $checkPos->save();

            foreach ($lineIds as $lineId) {
                \App\Models\PosProduct::where('id', $lineId)->update(['pos_id' => $checkPos->id]);
            }

            $createdChecks[] = [
                'pos_id' => $checkPos->id,
                'label' => $label,
                'line_count' => count($lineIds),
            ];
        }

        foreach ($sourcePosList as $pos) {
            $pos->refresh();
            $activeLines = $pos->items()->whereNull('voided_at')->whereNull('removed_from_cart_at')->count();
            if ($activeLines === 0) {
                $pos->cancelled_at = now();
                $pos->save();
            }
        }

        PosAuditLogService::log(
            PosAuditLog::ACTION_SEND_ORDER,
            'pos',
            (int) $parentPos->id,
            __('pos.split_bill_item_based'),
            ['table_id' => $tableId, 'checks' => count($createdChecks)]
        );

        return response()->json([
            'success' => true,
            'message' => __('pos.split_bill_success'),
            'checks' => $createdChecks,
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
            if (! $this->isActivePosBillLine($product)) {
                continue;
            }

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

        $pos->itemData = $items;
        $pos->totalTaxPrice = $totalTaxPrice;
        $pos->totalQuantity = $totalQuantity;
        $pos->totalRate = $totalRate;
        $pos->totalDiscount = $totalDiscount;
        $pos->taxesData = $taxesData;

        $logoBase = rtrim((string) Utility::get_file('uploads/logo'), '/');
        $company_logo = Utility::getValByName('company_logo_dark') ?: 'logo-dark.png';
        $pos_logo = Utility::getValByName('pos_logo');
        if (! empty($pos_logo)) {
            $img = rtrim((string) Utility::get_file('pos_logo'), '/') . '/' . ltrim((string) $pos_logo, '/');
        } else {
            $img = $logoBase . '/' . ltrim((string) $company_logo, '/');
        }

        if ($pos) {
            $color = '#' . $settings['pos_color'];
            $font_color = Utility::getFontColor($color);

            $custom = DocumentLayoutRenderer::tryRender('pos', $pos, [
                'settings' => $settings,
                'color' => $color,
                'font_color' => $font_color,
                'img' => $img,
                'party' => $customer,
            ]);
            if ($custom) {
                return $custom;
            }

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
                $itemTax['tax_price'] = $taxPrice;
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

        $logoBase = rtrim((string) Utility::get_file('uploads/logo'), '/');
        $company_logo = Utility::getValByName('company_logo_dark') ?: 'logo-dark.png';
        $settings_data = \App\Models\Utility::settingsById($pos->created_by);
        $pos_logo = $settings_data['pos_logo'] ?? Utility::getValByName('pos_logo');

        if (! empty($pos_logo)) {
            $img = rtrim((string) Utility::get_file('pos_logo'), '/') . '/' . ltrim((string) $pos_logo, '/');
        } else {
            $img = $logoBase . '/' . ltrim((string) $company_logo, '/');
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
        $post['pos_direct_print'] = ($request->input('pos_direct_print') === 'on') ? 'on' : 'off';
        $post['pos_qr_display'] = ($request->input('pos_qr_display') === 'on') ? 'on' : 'off';
        $post['pos_print_layout'] = Utility::normalizePosPrintLayout($request->input('pos_print_layout'));
        $post['pos_receipt_layout'] = Utility::normalizeReceiptLayout($request->input('pos_receipt_layout'));
        $post['uthara_print_pack'] = ($request->input('uthara_print_pack') === 'on') ? 'on' : 'off';
        $ufp = strtolower((string) $request->input('uthara_factura_paper', 'a4'));
        $post['uthara_factura_paper'] = in_array($ufp, ['a4', 'a5'], true) ? $ufp : 'a4';

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
            $pos = Pos::with(['items', 'customer', 'warehouse', 'posPayments', 'table', 'branch'])->where('id', $request->pos_id)->where('created_by', $user->creatorId())->first();
            if ($pos) {
                $posPayments = $pos->posPayments;
                $receiptUser = ($pos->pos_user_id ? User::find((int) $pos->pos_user_id) : null) ?: $user;
                $customer = $pos->customer_id ? $pos->customer : null;
                $warehouse = $pos->warehouse;
                $branch = $pos->branch;
                $isBalancePayment = ($pos->order_type ?? '') === 'balance_payment';

                $clientName = !empty($pos->guest_name) ? $pos->guest_name : (!empty($customer->name) ? $customer->name : __('pos.walkin_customer'));
                $details = [
                    'pos_id' => $user->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
                    'customer' => $customer ? $customer->toArray() : [],
                    'warehouse' => $warehouse ? $warehouse->toArray() : [],
                    'branch' => $branch ? $branch->toArray() : [],
                    'user' => $user->toArray(),
                    'date' => $pos->pos_date ?? date('Y-m-d'),
                    'client_name' => $clientName,
                    'table_name' => $pos->table ? $pos->table->name : null,
                    'document_type' => $pos->document_type ?? null,
                ];
                $warehouseName = $details['warehouse']['name'] ?? ($warehouse ? $warehouse->name : '-');
                $details['warehouse']['details'] = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';
                if (!empty($details['customer']['name'])) {
                    $details['customer']['details'] = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '</h6>';
                } else {
                    $details['customer']['details'] = '<h2 class="h6"><b>' . __('pos.walkin_customer') . '</b></h2>';
                }
                $details['customer']['shippdetails'] = '-';
                $details['user']['details'] = '<h6 class="text-dark"><b>' . ucfirst($user->name) . '</b></h6>';
                $sales = ['data' => [], 'discount' => '0', 'sub_total' => '0', 'total' => '0', 'tip' => '0'];
                $mainsubtotal = 0;
                $discount = $posPayments->isEmpty() ? 0 : (float) $posPayments->sum('discount');
                $totalTip = (float) $posPayments->sum('tip');
                if ($isBalancePayment) {
                    $totalAmount = (float) $posPayments->sum('amount');
                    $sales['sub_total'] = $user->priceFormat($totalAmount);
                    $sales['total'] = $user->priceFormat($totalAmount);
                    $newBalance = $pos->customer_id ? Customer::totalOutstanding($pos->customer_id) : 0;
                    $balancePaymentExtra = [
                        'invoices_paid' => [],
                        'new_balance_formatted' => $user->priceFormat($newBalance),
                    ];
                    $bankAccounts = BankAccount::where('created_by', $user->creatorId())
                        ->select('id', 'holder_name', 'bank_name', 'account_number')->get();
                    $barcode = ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()];
                    $posIdRaw = $pos->id;
                    $receiptLinkUrl = URL::signedRoute('pos.receipt.link', ['id' => Crypt::encrypt($pos->id)]);
                    $printSource = in_array($request->get('source'), ['restaurant'], true) ? 'restaurant' : 'retail';
                    return view(Utility::posReceiptViewName($settings ?? null), [
                        'details' => $details,
                        'sales' => $sales,
                        'customer' => $customer,
                        'productServices' => collect([]),
                        'barcode' => $barcode,
                        'posPayments' => $posPayments,
                        'vatBreakdown' => [],
                        'bankAccounts' => $bankAccounts,
                        'segundaVia' => $request->boolean('segunda_via'),
                        'posIdRaw' => $posIdRaw,
                        'receiptLinkUrl' => $receiptLinkUrl,
                        'printSource' => $printSource,
                        'isBalancePayment' => true,
                        'balancePaymentExtra' => $balancePaymentExtra,
                        'settings' => $settings,
                        'receiptUser' => $receiptUser,
                    ]);
                }
                foreach ($pos->items as $item) {
                    if (! $this->isActivePosBillLine($item)) {
                        continue;
                    }
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
                        'sku' => $product ? ($product->sku ?? '-') : '-',
                        'quantity' => $qty,
                        'price' => $user->priceFormat($price),
                        'tax' => $taxRate . '%',
                        'product_tax' => $taxRate . '%',
                        'tax_amount' => $user->priceFormat($tax),
                        'subtotal' => $user->priceFormat($lineTotal),
                        'description' => $item->description ?? '',
                    ];
                }
                $sales['discount'] = $user->priceFormat($discount);
                $sales['sub_total'] = $user->priceFormat($mainsubtotal);
                $sales['tip'] = $user->priceFormat($totalTip);
                $sales['total'] = $user->priceFormat($mainsubtotal - $discount);
                $vatBreakdown = [];
                foreach ($pos->items as $item) {
                    if (! $this->isActivePosBillLine($item)) {
                        continue;
                    }
                    $price = (float) $item->price;
                    $qty = (int) $item->quantity;
                    $taxRate = (float) Utility::totalTaxRate($item->tax);
                    $net = $price * $qty;
                    $vatAmount = ($net * $taxRate) / 100;
                    $key = (string) $taxRate;
                    if (!isset($vatBreakdown[$key])) {
                        $vatBreakdown[$key] = ['rate' => $taxRate, 'net' => 0, 'vat' => 0, 'amount' => 0];
                    }
                    $vatBreakdown[$key]['net'] += $net;
                    $vatBreakdown[$key]['vat'] += $vatAmount;
                    $vatBreakdown[$key]['amount'] += $net + $vatAmount;
                }
                $vatBreakdown = array_values($vatBreakdown);
                $bankAccounts = BankAccount::where('created_by', $user->creatorId())
                    ->select('id', 'holder_name', 'bank_name', 'account_number')->get();
                $productServices = ProductService::where('created_by', $user->creatorId())->get();
                $barcode = ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()];
                $segundaVia = $request->boolean('segunda_via');
                $posIdRaw = $pos->id;
                $receiptLinkUrl = URL::signedRoute('pos.receipt.link', ['id' => Crypt::encrypt($pos->id)]);
                \Log::info('Receipt link URL: ' . $receiptLinkUrl);
                $printSource = in_array($request->get('source'), ['restaurant'], true) ? 'restaurant' : 'retail';
                return view(Utility::posReceiptViewName($settings ?? null), compact('details', 'sales', 'customer', 'productServices', 'barcode', 'posPayments', 'vatBreakdown', 'bankAccounts', 'segundaVia', 'posIdRaw', 'receiptLinkUrl', 'printSource', 'receiptUser', 'pos'));
            }
        }

        $segundaVia = $request->boolean('segunda_via');
        $printSource = in_array($request->get('source'), ['restaurant'], true) ? 'restaurant' : 'retail';
        $sess = session()->get('pos');
        $customer = Customer::where('name', '=', $request->vc_name)->where('created_by', $user->creatorId())->first();
        $warehouse = warehouse::where('id', '=', $request->warehouse_name)->where('created_by', $user->creatorId())->first();

        if($sess === null){
            return ;
        }
        $details = [
            'pos_id' => $user->posNumberFormat($this->invoicePosNumber()),
            'customer' => $customer != null ? $customer->toArray() : [],
            'warehouse' => $warehouse != null ? $warehouse->toArray() : [],
            'user' => $user != null ? $user->toArray() : [],
            'date' => date('Y-m-d'),
            'pay' => 'show',
        ];
        if ($request->get('table_name')) {
            $details['client_name'] = __('pos.table') . ' ' . $request->get('table_name');
        } elseif ($customer) {
            $details['client_name'] = $customer->name;
        } else {
            $details['client_name'] = __('pos.walkin_customer');
        }

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

            $priceType = $value['price_type'] ?? 'exclusive';
            $lineSubtotal = (float) ($value['subtotal'] ?? 0);
            $taxRate = (float) ($value['tax'] ?? 0);
            if ($priceType === 'inclusive' && $taxRate > 0) {
                $tax = $lineSubtotal - ($lineSubtotal / (1 + $taxRate / 100));
            } else {
                $subtotal = ($value['price'] ?? 0) * ($value['quantity'] ?? 0);
                $tax = ($subtotal * $taxRate) / 100;
            }
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
        $sales['tip'] = Auth::user()->priceFormat(0);

        //for barcode

        $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())->get();
        $barcode = [
            'barcodeType' => Auth::user()->barcodeType(),
            'barcodeFormat' => Auth::user()->barcodeFormat(),
        ];

        return view(Utility::posReceiptViewName($settings ?? null), compact('details', 'sales', 'customer', 'productServices', 'barcode', 'posPayments', 'segundaVia', 'printSource'));

    }

    /**
     * Restaurant: show Bill before payment. Loads current cart (table or delivery/takeaway) and redirects to printview.
     */
    public function billView(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $sess = session()->get('pos');
        $tableId = (int) $request->get('pos_table_id');
        $shiftId = (int) $request->get('pos_shift_id');
        $orderType = $request->get('pos_order_type');
        $posId = (int) $request->get('pos_id');
        $creatorId = Auth::user()->creatorId();

        if ($tableId && $shiftId && (empty($sess) || !is_array($sess) || count($sess) === 0)) {
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
                    if (! $this->isActivePosBillLine($line)) {
                        continue;
                    }
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

        $sessEmpty = empty($sess) || !is_array($sess) || count($sess) === 0;
        if ($sessEmpty && $shiftId && in_array($orderType, [Pos::ORDER_TYPE_DELIVERY, Pos::ORDER_TYPE_TAKEAWAY], true)) {
            $cartKey = 'pos_restaurant_cart';
            $allCarts = $request->session()->get($cartKey, []);
            if ($posId > 0) {
                $pos = Pos::where('id', $posId)
                    ->whereNull('table_id')
                    ->where('order_type', $orderType)
                    ->where('shift_id', $shiftId)
                    ->where('created_by', $creatorId)
                    ->whereNull('cancelled_at')
                    ->whereDoesntHave('posPayments')
                    ->with(['items.product'])
                    ->first();
                if ($pos && $pos->items->isNotEmpty()) {
                    $sess = [];
                    foreach ($pos->items as $line) {
                        if (! $this->isActivePosBillLine($line)) {
                            continue;
                        }
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
                    $sessionCartKey = 'pos_' . $pos->id;
                    if (!empty($allCarts[$sessionCartKey])) {
                        foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                            $pid = is_numeric($pid) ? (int) $pid : $pid;
                            if (isset($sess[$pid])) {
                                $sess[$pid]['quantity'] += (int) ($v['quantity'] ?? 0);
                                $sess[$pid]['subtotal'] = ($sess[$pid]['price'] * $sess[$pid]['quantity']) * (1 + ($sess[$pid]['tax'] ?? 0) / 100);
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
            } else {
                $sessionCartKey = $orderType . '_' . $shiftId;
                if (!empty($allCarts[$sessionCartKey])) {
                    $sess = [];
                    foreach ($allCarts[$sessionCartKey] as $pid => $v) {
                        $pid = is_numeric($pid) ? (int) $pid : $pid;
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

        if (empty($sess) || !is_array($sess) || count($sess) === 0) {
            if ($request->ajax()) {
                return response()->json(['error' => __('Cart is empty. Add items before viewing the bill.')], 404);
            }
            return redirect()->back()->with('error', __('Cart is empty. Add items before viewing the bill.'));
        }

        if ($request->ajax()) {
            return $this->billModalContent($request, $sess, $tableId, $shiftId);
        }

        session()->put('pos', $sess);
        $params = array_merge($request->only(['warehouse_name', 'discount', 'vc_name', 'pos_table_id', 'pos_shift_id', 'pos_order_type', 'pos_id']), ['source' => 'restaurant']);
        if ($tableId && $shiftId) {
            $table = PosTable::find($tableId);
            if ($table) {
                $params['table_name'] = $table->name;
            }
        }
        return redirect()->route('pos.printview', $params);
    }

    /**
     * Build and return bill/receipt HTML for modal (same content as printview, no payment section).
     */
    protected function billModalContent(Request $request, array $sess, $tableId, $shiftId)
    {
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
        if ($tableId && $shiftId) {
            $table = PosTable::find($tableId);
            $details['client_name'] = $table ? __('pos.table') . ' ' . $table->name : __('pos.walkin_customer');
        } elseif ($customer) {
            $details['client_name'] = $customer->name;
        } else {
            $details['client_name'] = __('pos.walkin_customer');
        }

        $warehouseName = $details['warehouse']['name'] ?? ($warehouse ? $warehouse->name : '-');
        $warehousedetails = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';
        if (!empty($details['customer']['name'])) {
            $details['customer']['billing_state'] = ($details['customer']['billing_state'] ?? '') != '' ? ', ' . ($details['customer']['billing_state']) : '';
            $details['customer']['shipping_state'] = ($details['customer']['shipping_state'] ?? '') != '' ? ', ' . ($details['customer']['shipping_state']) : '';
            $customerdetails = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_phone'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_city'] ?? '') . ($details['customer']['billing_state'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_country'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['billing_zip'] ?? '') . '</p></h6>';
            $shippdetails = '<h6 class="text-dark"><b>' . ucfirst($details['customer']['name']) . '</b>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_phone'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_city'] ?? '') . ($details['customer']['shipping_state'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_country'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($details['customer']['shipping_zip'] ?? '') . '</p></h6>';
        } else {
            $customerdetails = '<h2 class="h6"><b>' . __('Walk-in Customer') . '</b></h2>';
            $shippdetails = '-';
        }
        $settings['company_telephone'] = ($settings['company_telephone'] ?? '') != '' ? ', ' . ($settings['company_telephone']) : '';
        $settings['company_state'] = ($settings['company_state'] ?? '') != '' ? ', ' . ($settings['company_state']) : '';
        $userName = $details['user']['name'] ?? ($user ? $user->name : '-');
        $userdetails = '<h6 class="text-dark"><b>' . ucfirst($userName) . ' </b> <h2  class="font-weight-normal">' . '<p class="m-0 font-weight-normal">' . ($settings['company_name'] ?? '') . ($settings['company_telephone'] ?? '') . '</p>' . '<p class="m-0 font-weight-normal">' . ($settings['company_address'] ?? '') . '</p>' . '<p class="m-0 h6 font-weight-normal">' . ($settings['company_city'] ?? '') . ($settings['company_state'] ?? '') . '</p>' . '<p class="m-0 font-weight-normal">' . ($settings['company_country'] ?? '') . '</p>' . '<p class="m-0 font-weight-normal">' . ($settings['company_zipcode'] ?? '') . '</p></h2>';
        $details['customer']['details'] = $customerdetails;
        $details['warehouse']['details'] = $warehousedetails;
        $details['customer']['shippdetails'] = $shippdetails;
        $details['user']['details'] = $userdetails;

        $mainsubtotal = 0;
        $sales = ['data' => []];
        foreach ($sess as $key => $value) {
            $priceType = $value['price_type'] ?? 'exclusive';
            $lineSubtotal = (float) ($value['subtotal'] ?? 0);
            $taxRate = (float) ($value['tax'] ?? 0);
            if ($priceType === 'inclusive' && $taxRate > 0) {
                $tax = $lineSubtotal - ($lineSubtotal / (1 + $taxRate / 100));
            } else {
                $subtotal = ($value['price'] ?? 0) * ($value['quantity'] ?? 0);
                $tax = ($subtotal * $taxRate) / 100;
            }
            $sales['data'][$key]['name'] = $value['name'];
            $sales['data'][$key]['quantity'] = $value['quantity'];
            $sales['data'][$key]['price'] = $user->priceFormat($value['price']);
            $sales['data'][$key]['tax'] = $value['tax'] . '%';
            $sales['data'][$key]['product_tax'] = $value['product_tax'];
            $sales['data'][$key]['tax_amount'] = $user->priceFormat($tax);
            $sales['data'][$key]['subtotal'] = $user->priceFormat($value['subtotal']);
            $mainsubtotal += $value['subtotal'];
        }
        $discount = !empty($request->discount) ? $request->discount : 0;
        $sales['discount'] = $user->priceFormat($discount);
        $sales['sub_total'] = $user->priceFormat($mainsubtotal);
        $sales['total'] = $user->priceFormat($mainsubtotal - $discount);
        $sales['tip'] = $user->priceFormat(0);

        $posPayments = collect();
        $segundaVia = false;
        $printSource = 'restaurant';
        $productServices = ProductService::where('created_by', $user->creatorId())->get();
        $barcode = ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()];

        $posIdRaw = null;
        if ($tableId && $shiftId) {
            $billPos = Pos::where('table_id', $tableId)
                ->where('shift_id', $shiftId)
                ->where('created_by', $user->creatorId())
                ->whereNull('cancelled_at')
                ->whereDoesntHave('posPayments')
                ->value('id');
            if ($billPos) {
                $posIdRaw = (int) $billPos;
            }
        }

        // Use selected Slip Layout (existing / compact1–3) for restaurant View Bill.
        return view(Utility::posReceiptViewName($settings ?? null), compact('details', 'sales', 'customer', 'productServices', 'barcode', 'posPayments', 'segundaVia', 'printSource', 'settings', 'posIdRaw'));
    }

    /**
     * Public receipt link (no auth). Scan QR on receipt to open this page.
     */
    public function receiptLink($encryptedId)
    {
        try {
            $id = Crypt::decrypt($encryptedId);
        } catch (\Throwable $e) {
            abort(404, __('Receipt not found.'));
        }
        $pos = Pos::with(['items', 'customer', 'warehouse', 'posPayments', 'table'])->find($id);
        if (!$pos) {
            abort(404, __('Receipt not found.'));
        }
        $user = User::find($pos->created_by);
        if (!$user) {
            abort(404, __('Receipt not found.'));
        }
        $settings = Utility::settingsById($pos->created_by);
        $posPayments = $pos->posPayments;
        $customer = $pos->customer_id ? $pos->customer : null;
        $warehouse = $pos->warehouse;
        $clientName = !empty($pos->guest_name) ? $pos->guest_name : (!empty($customer->name) ? $customer->name : __('pos.walkin_customer'));
        $details = [
            'pos_id' => $user->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
            'customer' => $customer ? $customer->toArray() : [],
            'warehouse' => $warehouse ? $warehouse->toArray() : [],
            'user' => $user->toArray(),
            'date' => $pos->pos_date ?? date('Y-m-d'),
            'client_name' => $clientName,
            'table_name' => $pos->table ? $pos->table->name : null,
        ];
        $warehouseName = $details['warehouse']['name'] ?? ($warehouse ? $warehouse->name : '-');
        $details['warehouse']['details'] = '<h7 class="text-dark">' . ucfirst($warehouseName) . '</p></h7>';
        if (!empty($details['customer']['name'])) {
            $details['customer']['details'] = '<h6 class="text-dark">' . ucfirst($details['customer']['name']) . '</h6>';
        } else {
            $details['customer']['details'] = '<h2 class="h6"><b>' . __('pos.walkin_customer') . '</b></h2>';
        }
        $details['customer']['shippdetails'] = '-';
        $details['user']['details'] = '<h6 class="text-dark"><b>' . ucfirst($user->name) . '</b></h6>';
        $sales = ['data' => [], 'discount' => '0', 'sub_total' => '0', 'total' => '0', 'tip' => '0'];
        $mainsubtotal = 0;
        $discount = 0;
        $totalTip = (float) $posPayments->sum('tip');
        foreach ($pos->items as $item) {
            if (! $this->isActivePosBillLine($item)) {
                continue;
            }
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
                'sku' => $product ? ($product->sku ?? '-') : '-',
                'quantity' => $qty,
                'price' => $user->priceFormat($price),
                'tax' => $taxRate . '%',
                'product_tax' => $taxRate . '%',
                'tax_amount' => $user->priceFormat($tax),
                'subtotal' => $user->priceFormat($lineTotal),
                'description' => $item->description ?? '',
            ];
        }
        $sales['discount'] = $user->priceFormat($discount);
        $sales['sub_total'] = $user->priceFormat($mainsubtotal);
        $sales['tip'] = $user->priceFormat($totalTip);
        $sales['total'] = $user->priceFormat($mainsubtotal - $discount);
        $vatBreakdown = [];
        foreach ($pos->items as $item) {
            if (! $this->isActivePosBillLine($item)) {
                continue;
            }
            $price = (float) $item->price;
            $qty = (int) $item->quantity;
            $taxRate = (float) Utility::totalTaxRate($item->tax);
            $net = $price * $qty;
            $vatAmount = ($net * $taxRate) / 100;
            $key = (string) $taxRate;
            if (!isset($vatBreakdown[$key])) {
                $vatBreakdown[$key] = ['rate' => $taxRate, 'net' => 0, 'vat' => 0, 'amount' => 0];
            }
            $vatBreakdown[$key]['net'] += $net;
            $vatBreakdown[$key]['vat'] += $vatAmount;
            $vatBreakdown[$key]['amount'] += $net + $vatAmount;
        }
        $vatBreakdown = array_values($vatBreakdown);
        $bankAccounts = BankAccount::where('created_by', $pos->created_by)
            ->select('id', 'holder_name', 'bank_name', 'account_number')->get();
        $productServices = ProductService::where('created_by', $pos->created_by)->get();
        $barcode = ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()];
        $segundaVia = false;
        $posIdRaw = $pos->id;
        $receiptLinkUrl = URL::signedRoute('pos.receipt.link', ['id' => Crypt::encrypt($pos->id)]);
        $printSource = 'retail';
        $receiptUser = $user;
        return view(Utility::posReceiptViewName($settings ?? null), compact('details', 'sales', 'customer', 'productServices', 'barcode', 'posPayments', 'vatBreakdown', 'bankAccounts', 'segundaVia', 'posIdRaw', 'receiptLinkUrl', 'settings', 'printSource', 'receiptUser', 'pos'))->with('standalone', true);
    }

    public function add_opening_balance()
    {
        // Opening balance is set when a shift is started (POS Shifts). Redirect to shifts.
        return redirect()->route('pos-shift.index')->with('info', __('pos.opening_balance_set_on_shift_start'));
    }

    
    public function add_closing_balance()
    {
        $userId = PosActorService::posActorId();
        $userOpenShift = \App\Models\PosShift::where('user_id', $userId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->where('created_by', \Auth::user()->creatorId())
            ->orderBy('opened_at', 'desc')
            ->first();
        if (!$userOpenShift) {
            if (request()->ajax() || request()->wantsJson()) {
                return response()->json(['error' => __('pos.open_shift_required_to_add_closing')], 422);
            }
            return redirect()->back()->with('error', __('pos.open_shift_required_to_add_closing'));
        }
        $posIds = Pos::where('shift_id', $userOpenShift->id)->pluck('id');
        $cashFromSales = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', 'cash')->sum('amount');
        $cashFromBalance = (float) InvoicePayment::where('pos_shift_id', $userOpenShift->id)->where('payment_method', 0)->sum('amount');
        $cashInsTotal = (float) \App\Models\PosCashIn::where('shift_id', $userOpenShift->id)->sum('amount');
        $cashDropsTotal = (float) \App\Models\PosCashDrop::where('shift_id', $userOpenShift->id)->sum('amount');
        $cashOutExpensesTotal = (float) \App\Models\Expense::where('pos_shift_id', $userOpenShift->id)->where('created_by', \Auth::user()->creatorId())->cashOutPosted()->sum('amount');
        $expectedCash = (float) ($userOpenShift->opening_balance ?? 0) + $cashFromSales + $cashFromBalance + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
        return view('pos.add_closing_balance', compact('userOpenShift', 'expectedCash', 'cashFromBalance'));
    }
    
    public function save_closing_balance(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'shift_id' => 'nullable|exists:pos_shifts,id',
            'tips' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);
        $userId = PosActorService::posActorId();
        $shiftId = $request->shift_id;
        $shift = $shiftId
            ? \App\Models\PosShift::where('id', $shiftId)->where('user_id', $userId)->where('created_by', \Auth::user()->creatorId())->where('status', \App\Models\PosShift::STATUS_OPEN)->first()
            : \App\Models\PosShift::where('user_id', $userId)->where('status', \App\Models\PosShift::STATUS_OPEN)->where('created_by', \Auth::user()->creatorId())->orderBy('opened_at', 'desc')->first();
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
        $balanceCashTotal = (float) InvoicePayment::where('pos_shift_id', $shift->id)->where('payment_method', 0)->sum('amount');
        $cashTotal += $balanceCashTotal;
        $cardTotal = PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = PosPayment::whereIn('pos_id', $posIds)->where('payment_method', 'credit')->sum('amount');
        $opening = (float) ($shift->opening_balance ?? 0);
        $closing = (float) $request->amount;
        $cashInsTotal = (float) \App\Models\PosCashIn::where('shift_id', $shift->id)->sum('amount');
        $cashDropsTotal = (float) \App\Models\PosCashDrop::where('shift_id', $shift->id)->sum('amount');
        $cashOutExpensesTotal = (float) \App\Models\Expense::where('pos_shift_id', $shift->id)->where('created_by', \Auth::user()->creatorId())->cashOutPosted()->sum('amount');
        $expectedCash = $opening + $cashTotal + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
        $difference = $closing - $expectedCash;

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

        $assignedTableIds = \App\Models\PosTableAssignment::where('shift_id', $shift->id)
            ->whereNull('unassigned_at')
            ->pluck('table_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        \App\Models\PosTableAssignment::where('shift_id', $shift->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now()]);
        if (! empty($assignedTableIds)) {
            \App\Models\PosTable::whereIn('id', $assignedTableIds)
                ->where('created_by', \Auth::user()->creatorId())
                ->update([
                    'status' => \App\Models\PosTable::STATUS_AVAILABLE,
                    'current_guests' => null,
                    'customer_name' => null,
                    'waiter_user_id' => null,
                ]);
        }

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
        $previousPinId = session('pos_pin_user_id');
        if ($previousPinId && (int) $previousPinId !== (int) $user->id) {
            PosActorService::clearRestaurantTerminalSession();
            PosAuditLogService::log(
                PosAuditLog::ACTION_SWITCH_USER,
                'user',
                (int) $user->id,
                __('POS user switched'),
                ['from_user_id' => (int) $previousPinId, 'to_user_id' => (int) $user->id]
            );
        }
        $request->session()->put('pos_pin_user_id', $user->id);
        PosActorService::establishPinActor((int) $user->id);
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
        $previousPinId = session('pos_pin_user_id');
        PosActorService::clearRestaurantTerminalSession();
        PosActorService::requireRestaurantPinEntry();
        if ($previousPinId) {
            PosAuditLogService::log(
                PosAuditLog::ACTION_SWITCH_USER,
                'user',
                (int) $previousPinId,
                __('POS user signed out'),
                ['from_user_id' => (int) $previousPinId]
            );
        }
        $request->session()->regenerate();

        return redirect()->route('pos.restaurant.index')->with('info', __('Enter PIN for the next user.'));
    }

    /**
     * Get current PIN user for restaurant POS (from session). Returns null if not set or invalid.
     */
    protected function getRestaurantPinUser()
    {
        return PosActorService::restaurantActor();
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
        $customers = Customer::forPos($creatorId)->get()->pluck('name', 'name');
        $customers->prepend('Walk-in-customer', '');
        $customersForPos = Customer::forPos($creatorId)->get(['id', 'name', 'email', 'contact', 'is_walk_in']);
        $customersForPos = collect([(object) ['id' => 0, 'name' => 'Walk-in-customer', 'email' => 'walkin@default.com', 'contact' => '0000000000', 'is_walk_in' => true]])->merge($customersForPos);
        $posPinUser->loadMissing('branches');
        $branchScopeUser = $this->restaurantBranchScopeUser();
        $branchScopeUser->loadMissing('branches');
        $restaurantBranchIds = $this->resolveRestaurantBranchIdsForActor($branchScopeUser, $creatorId);
        $posBranchLocked = $branchScopeUser->hasPosBranchRestriction() && count($restaurantBranchIds) === 1;
        $branches = Branch::whereIn('id', $restaurantBranchIds)->orderBy('name')->pluck('name', 'id');
        $warehouses = warehouse::with('branch')->where('created_by', $creatorId)
            ->whereIn('branch_id', $restaurantBranchIds)->get()->pluck('name', 'id');
        $posBranchAssignmentWarning = null;
        if ($restaurantBranchIds === [] && $branchScopeUser->pos_branch_id) {
            $assigned = Branch::where('id', $branchScopeUser->pos_branch_id)
                ->where('created_by', $creatorId)->first();
            if ($assigned && ! $assigned->isRestaurant()) {
                $posBranchAssignmentWarning = __('Your assigned POS branch is not a restaurant branch. Ask an admin to set branch POS type to Restaurant or assign restaurant branches under the user profile.');
            }
        } elseif ($restaurantBranchIds === []) {
            $posBranchAssignmentWarning = __('No restaurant branches are available. Create a branch with POS type Restaurant and assign it to this user (POS branch or Branches access).');
        }
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
        $canShowVoid = $user->canManagePos();
        $canVoidDirectly = PosAuditLogService::canVoidRestaurantLineDirectly($user);
        $canDeleteSent = PosAuditLogService::canDeleteSentRestaurantLines($user);
        $posDefaultPrepMinutes = (int) (Utility::getValByName('pos_default_prep_minutes') ?: 15);
        if ($posDefaultPrepMinutes < 1) {
            $posDefaultPrepMinutes = 15;
        }
        $companyUsers = User::where(function ($q) use ($creatorId) {
                $q->where('created_by', $creatorId)->orWhere('id', $creatorId);
            })
            ->where('type', '!=', 'client')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('pos.index-restaurant', compact('customers', 'customersForPos', 'branches', 'warehouses', 'details', 'customer', 'warehouseId', 'id', 'restaurantBranchIds', 'hasRetailBranches', 'hasRestaurantBranches', 'posMode', 'canChangeShift', 'userOpenShiftByBranch', 'userOpenShift', 'posBranchLocked', 'canShowPayButton', 'canShowVoid', 'canVoidDirectly', 'canDeleteSent', 'posPinUser', 'posBranchAssignmentWarning', 'posDefaultPrepMinutes', 'companyUsers'));
    }

    /**
     * Get customer outstanding balance for POS (JSON). Used when a customer is selected on Retail POS.
     */
    public function getCustomerBalance(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['success' => false, 'message' => __('Permission denied.')], 403);
        }
        $vcName = $request->input('vc_name', '');
        if (trim($vcName) === '' || $vcName === 'Walk-in-customer') {
            return response()->json(['success' => true, 'balance' => 0, 'formatted' => Auth::user()->priceFormat(0), 'customer_id' => 0]);
        }
        $customerId = Customer::customer_id($vcName);
        if (!$customerId) {
            return response()->json(['success' => true, 'balance' => 0, 'formatted' => Auth::user()->priceFormat(0), 'customer_id' => 0]);
        }
        $balance = Customer::totalOutstanding($customerId, (int) Auth::user()->creatorId());
        return response()->json([
            'success' => true,
            'balance' => $balance,
            'formatted' => Auth::user()->priceFormat($balance),
            'customer_id' => $customerId,
        ]);
    }

    /**
     * Get customer's outstanding invoices for POS Pay balance modal (list to show, select which to pay).
     */
    public function getCustomerOutstandingInvoices(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['success' => false, 'message' => __('Permission denied.')], 403);
        }
        $vcName = $request->input('vc_name', '');
        if (trim($vcName) === '' || $vcName === 'Walk-in-customer') {
            return response()->json(['success' => true, 'invoices' => [], 'total_outstanding' => 0, 'formatted' => Auth::user()->priceFormat(0)]);
        }
        $customerId = Customer::customer_id($vcName);
        if (!$customerId) {
            return response()->json(['success' => true, 'invoices' => [], 'total_outstanding' => 0, 'formatted' => Auth::user()->priceFormat(0)]);
        }
        $invoices = Invoice::where('customer_id', $customerId)
            ->where('created_by', Auth::user()->creatorId())
            ->whereIn('status', ['0', '1', '2', '3'])
            ->with('payments')
            ->orderBy('issue_date', 'asc')
            ->get();
        $list = [];
        $totalOutstanding = 0;
        foreach ($invoices as $inv) {
            $due = $inv->getDue();
            if ($due <= 0) {
                continue;
            }
            $list[] = [
                'id' => $inv->id,
                'invoice_id' => $inv->invoice_id,
                'ref_number' => $inv->ref_number ?? ('INV-' . $inv->invoice_id),
                'issue_date' => $inv->issue_date,
                'due_date' => $inv->due_date ?? $inv->issue_date,
                'due_amount' => round($due, 2),
                'due_formatted' => Auth::user()->priceFormat($due),
            ];
            $totalOutstanding += $due;
        }
        return response()->json([
            'success' => true,
            'invoices' => $list,
            'total_outstanding' => round($totalOutstanding, 2),
            'formatted' => Auth::user()->priceFormat($totalOutstanding),
        ]);
    }

    /**
     * Bank accounts list for POS pay-balance modal (JSON).
     */
    public function getPosBankAccounts(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['success' => false], 403);
        }
        $accounts = BankAccount::where('created_by', Auth::user()->creatorId())
            ->select('id', 'holder_name', 'bank_name', 'account_number')
            ->get()
            ->map(function ($a) {
                return ['id' => $a->id, 'label' => $a->holder_name . ' - ' . $a->bank_name . ' (' . $a->account_number . ')'];
            });
        return response()->json(['success' => true, 'accounts' => $accounts]);
    }

    /**
     * Apply payment to customer's outstanding invoices from POS (pay balance).
     */
    public function payCustomerBalance(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return response()->json(['success' => false, 'message' => __('Permission denied.')], 403);
        }
        $validator = \Validator::make($request->all(), [
            'vc_name' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:bank_accounts,id',
            'payment_method' => 'required|string|in:cash,card,visa,cheque',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->getMessageBag()->first()], 422);
        }
        $vcName = trim($request->vc_name);
        if ($vcName === '' || $vcName === 'Walk-in-customer') {
            return response()->json(['success' => false, 'message' => __('Select a customer.')], 422);
        }
        $customerId = Customer::customer_id($vcName);
        if (!$customerId) {
            return response()->json(['success' => false, 'message' => __('Customer not found.')], 422);
        }
        $customer = Customer::find($customerId);
        $totalOutstanding = Customer::totalOutstanding($customerId, (int) Auth::user()->creatorId());
        if ($totalOutstanding <= 0) {
            return response()->json(['success' => false, 'message' => __('Customer has no outstanding balance.')], 422);
        }
        $amount = (float) $request->amount;
        if ($amount > $totalOutstanding) {
            $amount = $totalOutstanding;
        }
        $accountId = (int) $request->account_id;
        $bankAccount = BankAccount::where('id', $accountId)->where('created_by', Auth::user()->creatorId())->first();
        if (!$bankAccount) {
            return response()->json(['success' => false, 'message' => __('Invalid account.')], 422);
        }

        $posShiftId = null;
        if ($request->has('pos_shift_id') && $request->pos_shift_id) {
            $shift = \App\Models\PosShift::where('id', (int) $request->pos_shift_id)
                ->where('created_by', Auth::user()->creatorId())
                ->where('status', \App\Models\PosShift::STATUS_OPEN)
                ->where('user_id', PosActorService::posActorId())
                ->first();
            if ($shift) {
                $posShiftId = $shift->id;
            }
        }

        $paymentMethod = $request->payment_method; // cash, card, visa, cheque
        $paymentMethodMap = ['cash' => 0, 'card' => 1, 'visa' => 2, 'cheque' => 3];
        $paymentMethodId = $paymentMethodMap[$paymentMethod] ?? 0;

        $invoiceIds = $request->input('invoice_ids', []);
        if (!is_array($invoiceIds)) {
            $invoiceIds = [];
        }
        $invoiceIds = array_filter(array_map('intval', $invoiceIds));

        $query = Invoice::where('customer_id', $customerId)
            ->where('created_by', Auth::user()->creatorId())
            ->whereIn('status', ['0', '1', '2', '3'])
            ->with('payments')
            ->orderBy('issue_date', 'asc');
        if (!empty($invoiceIds)) {
            $query->whereIn('id', $invoiceIds);
        }
        $invoices = $query->get();

        $remaining = $amount;
        $date = date('Y-m-d');
        $user = Auth::user();
        $paidInvoices = [];

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }
            $due = $invoice->getDue();
            if ($due <= 0) {
                continue;
            }
            $payThis = min($remaining, $due);

            $invoicePayment = new InvoicePayment();
            $invoicePayment->invoice_id = $invoice->id;
            $invoicePayment->date = $date;
            $invoicePayment->amount = $payThis;
            $invoicePayment->account_id = $accountId;
            $invoicePayment->payment_method = $paymentMethodId;
            $invoicePayment->reference = __('POS balance payment');
            $invoicePayment->description = 'POS ' . __('Balance payment') . ' (' . ucfirst($paymentMethod) . ')';
            if ($posShiftId) {
                $invoicePayment->pos_shift_id = $posShiftId;
            }
            $invoicePayment->save();

            $paidInvoices[] = ['ref_number' => $invoice->ref_number ?? ('INV-' . $invoice->invoice_id), 'amount' => $payThis];

            $invoice->load('payments');
            $newDue = $invoice->getDue();
            if ($invoice->status == 0) {
                $invoice->send_date = $date;
                $invoice->save();
            }
            if ($newDue <= 0) {
                $invoice->status = 4;
                $invoice->save();
            } else {
                $invoice->status = 3;
                $invoice->save();
            }

            $invoicePayment->user_id = $invoice->customer_id;
            $invoicePayment->user_type = 'Customer';
            $invoicePayment->type = 'Partial';
            $invoicePayment->created_by = $user->id;
            $invoicePayment->payment_id = $invoicePayment->id;
            $invoicePayment->category = 'Invoice';
            $invoicePayment->account = $accountId;
            $invoicePayment->description = $invoicePayment->description ?? 'Invoice Payment';

            Transaction::addTransaction($invoicePayment);
            if (method_exists(\App\Models\Utility::class, 'updateUserBalance')) {
                Utility::updateUserBalance('customer', $invoice->customer_id, $payThis, 'credit');
            }
            Utility::bankAccountBalance($accountId, $payThis, 'credit');

            $acc = BankAccount::find($accountId);
            if ($acc && $acc->chart_account_id) {
                $data = [
                    'account_id' => $acc->chart_account_id,
                    'transaction_type' => 'Debit',
                    'transaction_amount' => $payThis,
                    'reference' => 'Invoice Payment',
                    'reference_id' => $invoice->id,
                    'reference_sub_id' => $invoicePayment->id,
                    'date' => $date,
                ];
                Utility::addTransactionLines($data, 'create');
            }

            $remaining -= $payThis;
        }

        $newBalance = Customer::totalOutstanding($customerId);
        $receiptData = [
            'customer_name' => $customer ? $customer->name : $vcName,
            'date' => $date,
            'amount' => $amount,
            'amount_formatted' => $user->priceFormat($amount),
            'payment_method' => ucfirst($paymentMethod),
            'invoices_paid' => $paidInvoices,
            'new_balance' => $newBalance,
            'new_balance_formatted' => $user->priceFormat($newBalance),
        ];

        // Create a Pos record so balance payment has reference number, QR link, and appears in Pay Receipts search
        $nextPosId = $this->invoicePosNumber(Pos::DOCUMENT_TYPE_CASH_SALE);
        $defaultWarehouse = warehouse::where('created_by', $user->creatorId())->orderBy('id')->first();
        $warehouseId = $defaultWarehouse ? (int) $defaultWarehouse->id : 0;
        $defaultBranch = Branch::where('created_by', $user->creatorId())->orderBy('id')->first();
        $branchId = $defaultBranch ? (int) $defaultBranch->id : null;

        $pos = new Pos();
        $pos->pos_id = $nextPosId;
        $pos->document_type = Pos::DOCUMENT_TYPE_CASH_SALE;
        $pos->customer_id = $customerId;
        $pos->warehouse_id = $warehouseId;
        $pos->branch_id = $branchId;
        $pos->order_type = 'balance_payment';
        $pos->pos_date = $date;
        $pos->shift_id = $posShiftId;
        $pos->payment_method = $paymentMethod;
        $pos->created_by = $user->creatorId();
        $pos->save();

        $posPayment = new PosPayment();
        $posPayment->pos_id = $pos->id;
        $posPayment->branch_id = $pos->branch_id;
        $posPayment->date = $date;
        $posPayment->payment_method = in_array($paymentMethod, ['cash', 'card', 'visa', 'cheque'], true) ? $paymentMethod : 'cash';
        $posPayment->amount = $amount;
        $posPayment->discount = 0;
        $posPayment->discount_amount = $amount;
        $posPayment->tip = 0;
        $posPayment->transaction_id = $request->filled('transaction_id') ? trim($request->transaction_id) : null;
        $posPayment->created_by = $user->id;
        $posPayment->save();

        $settings = Utility::settings();
        $details = [
            'pos_id' => $user->posNumberFormat($pos->pos_id, $pos->document_type ?? null),
            'client_name' => $receiptData['customer_name'],
            'customer' => $customer ? $customer->toArray() : [],
            'warehouse' => $defaultWarehouse ? $defaultWarehouse->toArray() : ['name' => '-'],
            'user' => $user->toArray(),
            'date' => $date,
        ];
        $posPayments = $pos->posPayments;
        $bankAccounts = BankAccount::where('created_by', $user->creatorId())
            ->select('id', 'holder_name', 'bank_name', 'account_number')->get();
        $balancePaymentExtra = [
            'invoices_paid' => $paidInvoices,
            'new_balance_formatted' => $user->priceFormat($newBalance),
        ];
        $posIdRaw = $pos->id;
        $receiptLinkUrl = URL::signedRoute('pos.receipt.link', ['id' => Crypt::encrypt($pos->id)]);
        $receiptHtml = view(Utility::posReceiptViewName($settings ?? null), [
            'details' => $details,
            'sales' => ['data' => [], 'discount' => '0', 'sub_total' => $user->priceFormat($amount), 'total' => $user->priceFormat($amount), 'tip' => '0'],
            'customer' => $customer,
            'productServices' => collect([]),
            'barcode' => ['barcodeType' => $user->barcodeType(), 'barcodeFormat' => $user->barcodeFormat()],
            'posPayments' => $posPayments,
            'vatBreakdown' => [],
            'bankAccounts' => $bankAccounts,
            'segundaVia' => false,
            'posIdRaw' => $posIdRaw,
            'receiptLinkUrl' => $receiptLinkUrl,
            'printSource' => 'retail',
            'isBalancePayment' => true,
            'balancePaymentExtra' => $balancePaymentExtra,
            'settings' => $settings,
        ])->render();

        return response()->json([
            'success' => true,
            'message' => __('Payment successfully added.'),
            'balance' => $newBalance,
            'formatted' => $user->priceFormat($newBalance),
            'receipt' => $receiptData,
            'receipt_html' => $receiptHtml,
            'pos_id' => $pos->id,
        ]);
    }

}
