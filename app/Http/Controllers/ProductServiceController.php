<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;
use App\Models\CustomField;
use App\Exports\ProductServiceExport;
use App\Imports\ProductServiceImport;
use App\Models\Product;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\ProductServiceUnit;
use App\Models\Tax;
use App\Models\User;
use App\Models\Utility;
use App\Models\Vender;
use App\Models\WarehouseProduct;
use App\Models\PriceLevel;
use App\Models\PosPrinter;
use App\Models\ProductKitItem;
use App\Models\ProductRecipe;
use App\Models\ProductVariant;
use App\Models\PurchaseProduct;
use App\Services\BatchLotDeductionService;
use App\Services\PosFefoDeductionService;
use App\Services\PosPrintRoutingService;
use App\Services\ProductOptionService;
use App\Models\ProductOptionItem;
use Google\Service\Dataproc\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;




class ProductServiceController extends Controller
{
    /** Bootstrap grid classes for POS product cards (restaurant + retail). */
    private const POS_PRODUCT_CARD_COL_CLASS = 'col-xl-3 col-lg-3 col-md-3 col-sm-4 col-6';

    /** Bump when POS product card HTML shape changes (invalidates Laravel + client caches). */
    private const POS_PRODUCT_HTML_CACHE_VER = 'pv5';

    protected function buildCategoryOptions($categories, $parentId = null, string $prefix = ''): array
    {
        $out = [];
        foreach ($categories->where('parent_id', $parentId)->sortBy('name') as $c) {
            $out[$c->id] = $prefix . $c->name;
            $out += $this->buildCategoryOptions($categories, $c->id, $prefix . '— ');
        }
        return $out;
    }

    /**
     * POS category filter: include subcategories when filtering by a parent category.
     *
     * @return array<int, int>|null null = no category filter (all products)
     */
    protected function resolvePosCategoryFilterIds($catId): ?array
    {
        if ($catId === null || $catId === '' || $catId === '0' || $catId === 0) {
            return null;
        }

        $id = (int) $catId;
        $creatorId = \Auth::user()->creatorId();
        $all = ProductServiceCategory::query()
            ->where('created_by', $creatorId)
            ->where('type', 'product & service')
            ->get(['id', 'parent_id']);

        $hasChildren = $all->contains(fn ($c) => (int) ($c->parent_id ?? 0) === $id);
        if ($hasChildren) {
            return array_values(array_unique(array_merge(
                [$id],
                ProductServiceCategory::descendantIds($id, $all)
            )));
        }

        return [$id];
    }

    protected function applyPosCategoryFilter($query, $catId)
    {
        $ids = $this->resolvePosCategoryFilterIds($catId);
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('product_services.category_id', $ids);
    }

    public function index(Request $request)
    {
        if (\Auth::user()->can('manage product & service')) {
            $category = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->where('type', '=', 'product & service')->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');

            return view('productservice.index', compact('category'));
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function datatable(Request $request)
    {
        if (!\Auth::user()->can('manage product & service')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $user = \Auth::user();
        $creatorId = $user->creatorId();
        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 25);
        if ($length < 1) {
            $length = 25;
        }
        if ($length > 100) {
            $length = 100;
        }

        $categoryId = $request->input('category');
        if ($categoryId === '' || $categoryId === null || $categoryId === '0') {
            $categoryId = null;
        } else {
            $categoryId = (int) $categoryId;
        }
        $searchValue = trim((string) $request->input('search.value', ''));
        $hasSearch = $searchValue !== '';

        // Fast count without joins (cached); avoid recounting on every page click.
        $recordsTotal = $this->cachedProductServiceCount($creatorId, $categoryId);

        $filterQuery = $this->productServiceFilterQuery($creatorId, $categoryId, $hasSearch);

        if ($hasSearch) {
            $this->applyProductServiceListSearch($filterQuery, $searchValue);
            $recordsFiltered = (clone $filterQuery)->distinct('product_services.id')->count('product_services.id');
        } else {
            $recordsFiltered = $recordsTotal;
        }

        [$orderBy, $orderDir] = $this->resolveProductServiceDatatableOrder($request);

        $idsQuery = (clone $filterQuery)->select('product_services.id');
        if ($hasSearch) {
            $idsQuery->groupBy('product_services.id');
        }
        $idsQuery->orderBy($orderBy, $orderDir)->orderBy('product_services.id', $orderDir);

        $productIds = $idsQuery->skip($start)->take($length)->pluck('id');

        if ($productIds->isEmpty()) {
            $products = collect();
        } else {
            $ids = $productIds->map(fn ($id) => (int) $id)->values()->all();
            $products = $this->productServiceListQuery($creatorId, $categoryId)
                ->whereIn('product_services.id', $ids)
                ->orderByRaw('FIELD(product_services.id, '.implode(',', $ids).')')
                ->get();
        }

        $canEdit = Gate::check('edit product & service');
        $canDelete = Gate::check('delete product & service');
        $showActions = $canEdit || $canDelete;

        $taxData = $this->cachedTaxDataForCreator($creatorId);
        $productBaseUrl = rtrim(url('productservice'), '/');
        $csrfToken = csrf_token();
        $formatPrice = $this->priceFormatterForDatatable();
        $rowContext = [
            'formatPrice' => $formatPrice,
            'taxData' => $taxData,
            'productBaseUrl' => $productBaseUrl,
            'csrfToken' => $csrfToken,
            'labels' => [
                'shelf' => __('Shelf'),
                'row' => __('Row'),
                'warehouse' => __('Warehouse Details'),
                'edit' => __('Edit'),
                'edit_product' => __('Edit Product'),
                'delete' => __('Delete'),
            ],
        ];

        $data = $products->map(function (ProductService $product) use ($canEdit, $canDelete, $showActions, $rowContext) {
            return $this->formatProductServiceRow($product, $canEdit, $canDelete, $showActions, $rowContext);
        })->values()->all();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * Filter query for counts / ID pagination (joins only when searching).
     *
     * @return \Illuminate\Database\Eloquent\Builder<ProductService>
     */
    private function productServiceFilterQuery(int $creatorId, $categoryId = null, bool $withJoins = false)
    {
        $query = ProductService::query()
            ->where('product_services.created_by', $creatorId);

        if (! empty($categoryId)) {
            $query->where('product_services.category_id', $categoryId);
        }

        if ($withJoins) {
            $query->leftJoin('product_service_categories as psc', 'psc.id', '=', 'product_services.category_id')
                ->leftJoin('product_service_units as psu', 'psu.id', '=', 'product_services.unit_id');
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveProductServiceDatatableOrder(Request $request): array
    {
        $order = $request->input('order', []);
        $columns = $request->input('columns', []);

        $columnIndex = 0;
        $dir = 'asc';
        if (is_array($order) && isset($order[0]['column'])) {
            $columnIndex = (int) $order[0]['column'];
            $dir = strtolower((string) ($order[0]['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        }

        $allowed = [
            'name' => 'product_services.name',
            'sku' => 'product_services.sku',
            'sale_price' => 'product_services.sale_price',
            'purchase_price' => 'product_services.purchase_price',
            'quantity' => 'product_services.quantity',
            'type' => 'product_services.type',
        ];

        $colName = null;
        if (is_array($columns) && isset($columns[$columnIndex])) {
            $col = $columns[$columnIndex];
            $orderable = ($col['orderable'] ?? 'true') !== 'false' && ($col['orderable'] ?? true) !== false;
            if ($orderable && ! empty($col['name'])) {
                $colName = (string) $col['name'];
            }
        }

        if ($colName === null || ! isset($allowed[$colName])) {
            $indexMap = [
                0 => 'name',
                1 => 'sku',
                2 => 'sale_price',
                3 => 'purchase_price',
                8 => 'quantity',
                10 => 'type',
            ];
            $colName = $indexMap[$columnIndex] ?? 'name';
        }

        return [$allowed[$colName] ?? 'product_services.name', $dir];
    }

    /**
     * Base list query with joins (avoids slow whereHas subqueries).
     *
     * @return \Illuminate\Database\Eloquent\Builder<ProductService>
     */
    private function productServiceListQuery(int $creatorId, $categoryId = null)
    {
        $query = ProductService::query()
            ->select([
                'product_services.id',
                'product_services.name',
                'product_services.sku',
                'product_services.sale_price',
                'product_services.purchase_price',
                'product_services.tax_id',
                'product_services.price_level_id',
                'product_services.category_id',
                'product_services.unit_id',
                'product_services.quantity',
                'product_services.type',
                'product_services.shelf_no',
                'product_services.row_no',
                'psc.name as category_name',
                'psu.name as unit_name',
                'pl.name as price_level_name',
                'pl.type as price_level_type',
                'pl.value as price_level_value',
            ])
            ->leftJoin('product_service_categories as psc', 'psc.id', '=', 'product_services.category_id')
            ->leftJoin('product_service_units as psu', 'psu.id', '=', 'product_services.unit_id')
            ->leftJoin('price_levels as pl', 'pl.id', '=', 'product_services.price_level_id')
            ->where('product_services.created_by', $creatorId);

        if (! empty($categoryId)) {
            $query->where('product_services.category_id', $categoryId);
        }

        return $query;
    }

    private function applyProductServiceListSearch($query, string $searchValue): void
    {
        $like = '%'.$searchValue.'%';
        $query->where(function ($q) use ($like) {
            $q->where('product_services.name', 'like', $like)
                ->orWhere('product_services.sku', 'like', $like)
                ->orWhere('product_services.type', 'like', $like)
                ->orWhere('psc.name', 'like', $like)
                ->orWhere('psu.name', 'like', $like);
        });
    }

    private function cachedProductServiceCount(int $creatorId, $categoryId): int
    {
        $cacheKey = $this->productServiceCountCacheKey($creatorId, $categoryId);

        return (int) Cache::remember($cacheKey, now()->addMinutes(10), function () use ($creatorId, $categoryId) {
            $query = ProductService::query()->where('created_by', $creatorId);
            if (! empty($categoryId)) {
                $query->where('category_id', $categoryId);
            }

            return $query->count();
        });
    }

    private function productServiceCountCacheKey(int $creatorId, $categoryId): string
    {
        $version = (int) Cache::get('productservice_datatable_count_ver_'.$creatorId, 1);

        return 'productservice_datatable_count_'.$creatorId.'_v'.$version.'_'.($categoryId ?: 'all');
    }

    private function bumpProductServiceDatatableCountCache(int $creatorId): void
    {
        $versionKey = 'productservice_datatable_count_ver_'.$creatorId;
        if (! Cache::has($versionKey)) {
            Cache::put($versionKey, 1, now()->addYear());
        } else {
            Cache::increment($versionKey);
        }
    }

    /**
     * @return array<int, array{name: string, rate: mixed}>
     */
    private function cachedTaxDataForCreator(int $creatorId): array
    {
        return Cache::remember(
            'productservice_datatable_tax_'.$creatorId,
            now()->addMinutes(30),
            fn () => $this->taxDataForCreator($creatorId)
        );
    }

    /**
     * @return array<int, array{name: string, rate: mixed}>
     */
    private function taxDataForCreator(int $creatorId): array
    {
        $data = [];
        foreach (Tax::where('created_by', $creatorId)->get(['id', 'name', 'rate']) as $rate) {
            $data[$rate->id] = ['name' => $rate->name, 'rate' => $rate->rate];
        }

        return $data;
    }

    /**
     * Format prices once per request (User::priceFormat reloads Utility::settings() every call).
     *
     * @return \Closure(float|int|string|null): string
     */
    private function priceFormatterForDatatable(): \Closure
    {
        $settings = Utility::settings();

        return function ($price) use ($settings) {
            return $this->formatPriceFromSettings($price, $settings);
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function formatPriceFromSettings($price, array $settings): string
    {
        $price = (float) $price;
        $number = explode('.', (string) $price);
        $length = strlen(trim($number[0]));
        $floatNumber = ($settings['float_number'] ?? 'dot') === 'dot' ? '.' : ',';

        if ($length > 3) {
            $decimalSeparator = ($settings['decimal_separator'] ?? 'dot') === 'dot' ? ',' : ',';
            $thousandSeparator = ($settings['thousand_separator'] ?? 'dot') === 'dot' ? '.' : ',';
        } else {
            $decimalSeparator = ($settings['decimal_separator'] ?? 'dot') === 'dot' ? '.' : ',';
            $thousandSeparator = ($settings['thousand_separator'] ?? 'dot') === 'dot' ? '.' : ',';
        }

        $currency = ($settings['currency_symbol'] ?? '') === 'withcurrencysymbol'
            ? ($settings['site_currency_symbol'] ?? '')
            : ($settings['site_currency'] ?? '');
        $decimalNumber = ! empty($settings['decimal_number']) ? (int) $settings['decimal_number'] : 0;
        $currencySpace = $settings['currency_space'] ?? '';
        $formatted = number_format($price, $decimalNumber, $decimalSeparator, $thousandSeparator);

        if ($floatNumber === '.') {
            $formatted = preg_replace('/'.preg_quote($thousandSeparator, '/').'([^'.preg_quote($thousandSeparator, '/').']*)$/', $floatNumber.'$1', $formatted);
        } else {
            $formatted = preg_replace('/'.preg_quote($decimalSeparator, '/').'([^'.preg_quote($decimalSeparator, '/').']*)$/', $floatNumber.'$1', $formatted);
        }

        $space = $currencySpace === 'withspace' ? ' ' : '';

        return (($settings['site_currency_symbol_position'] ?? '') === 'pre' ? $currency : '')
            .$space.$formatted.$space
            .(($settings['site_currency_symbol_position'] ?? '') === 'post' ? $currency : '');
    }

    /**
     * @param  array{formatPrice: \Closure, taxData: array, productBaseUrl: string, csrfToken: string, labels: array<string, string>}  $context
     * @return array<int, string>
     */
    private function formatProductServiceRow(ProductService $productService, bool $canEdit, bool $canDelete, bool $showActions, array $context): array
    {
        $formatPrice = $context['formatPrice'];
        $taxData = $context['taxData'];
        $labels = $context['labels'];

        $taxHtml = '-';
        if (! empty($productService->tax_id)) {
            $parts = [];
            foreach (explode(',', $productService->tax_id) as $tax) {
                $tax = trim($tax);
                if ($tax === '' || ! isset($taxData[$tax])) {
                    continue;
                }
                $parts[] = '<span>'.e($taxData[$tax]['name'].' ('.$taxData[$tax]['rate'].'%)').'</span>';
            }
            if (! empty($parts)) {
                $taxHtml = implode('<br>', $parts);
            }
        }

        $discountHtml = '-';
        if (! empty($productService->price_level_id) && ! empty($productService->price_level_name)) {
            $discountHtml = e($productService->price_level_name.' - '.$productService->price_level_type.' - '.$productService->price_level_value);
        }

        $quantityHtml = in_array($productService->type, ['product', 'kit', 'ingredient'], true)
            ? e((string) $productService->quantity)
            : '-';

        $locationHtml = '-';
        if (! empty($productService->shelf_no) || ! empty($productService->row_no)) {
            $locationHtml = e($labels['shelf']).': '.e($productService->shelf_no ?? '-')
                .'<br>'.e($labels['row']).': '.e($productService->row_no ?? '-');
        }

        $categoryName = $productService->category_name ?? '';
        $unitName = $productService->unit_name ?? '';

        $actionHtml = '';
        if ($showActions) {
            $id = (int) $productService->id;
            $base = $context['productBaseUrl'];
            $csrf = e($context['csrfToken']);
            $actionHtml = '<div class="action-btn me-2 d-inline-block">'
                .'<a href="#" class="mx-3 btn btn-sm align-items-center bg-warning" data-url="'.e($base.'/'.$id.'/detail').'"'
                .' data-ajax-popup="true" data-bs-toggle="tooltip" title="'.e($labels['warehouse']).'" data-title="'.e($labels['warehouse']).'">'
                .'<i class="ti ti-eye text-white"></i></a></div>';

            if ($canEdit) {
                $actionHtml .= '<div class="action-btn me-2 d-inline-block">'
                    .'<a href="#" class="mx-3 btn btn-sm align-items-center bg-info" data-url="'.e($base.'/'.$id.'/edit').'"'
                    .' data-ajax-popup="true" data-size="lg" data-bs-toggle="tooltip" title="'.e($labels['edit']).'" data-title="'.e($labels['edit_product']).'">'
                    .'<i class="ti ti-pencil text-white"></i></a></div>';
            }

            if ($canDelete) {
                $actionHtml .= '<div class="action-btn d-inline-block">'
                    .'<form method="POST" action="'.e($base.'/'.$id).'" id="delete-form-'.$id.'" class="d-inline">'
                    .'<input type="hidden" name="_token" value="'.$csrf.'"><input type="hidden" name="_method" value="DELETE">'
                    .'<a href="#" class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger" data-bs-toggle="tooltip" title="'.e($labels['delete']).'">'
                    .'<i class="ti ti-trash text-white"></i></a></form></div>';
            }
        }

        return [
            e($productService->name),
            e($productService->sku),
            e($formatPrice($productService->sale_price)),
            e($formatPrice($productService->purchase_price)),
            $taxHtml,
            $discountHtml,
            e($categoryName),
            e($unitName),
            $quantityHtml,
            $locationHtml,
            e(ucwords((string) $productService->type)),
            $actionHtml,
        ];
    }


    public function create()
    {
        if (\Auth::user()->can('create product & service')) {
            $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'product')->get();
            $rawCats = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())
                ->where('type', '=', 'product & service')
                ->get(['id', 'name', 'parent_id']);
            $category = collect($this->buildCategoryOptions($rawCats));
            $category->prepend('Select Category', '');
            $units = ProductServiceUnit::where('created_by', '=', \Auth::user()->creatorId())->get();
            $unit = $units->pluck('name', 'id');
            $tax          = Tax::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $ulane = auth()->user()->lang ?? 'en';
            $name_column = match ($ulane) {
                'fr' => 'name_fr',
                'zh' => 'name_zh',
                'es' => 'name_es',
                'pt' => 'name_pt_br',
                default => 'name',
            };
            $incomeChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name, chart_of_accounts.id as id'))
                ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                ->where('chart_of_account_types.name_pt_br', 'Rendimento')
                ->where('parent', '=', 0)
                ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                ->pluck('code_name', 'id');
            $incomeChartAccounts->prepend('Select Account', 0);

            $incomeSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
            $incomeSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $incomeSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
            $incomeSubAccounts->where('chart_of_account_types.name_pt_br', 'Rendimento');
            $incomeSubAccounts->where('chart_of_accounts.parent', '!=', 0);
            $incomeSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $incomeSubAccounts = $incomeSubAccounts->get()->toArray();


            $expenseChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name, chart_of_accounts.id as id'))
                ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                ->whereIn('chart_of_account_types.name_pt_br', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)'])
                ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                ->pluck('code_name', 'id');
            $expenseChartAccounts->prepend('Select Account', '');

            $expenseSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
            $expenseSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $expenseSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
            $expenseSubAccounts->whereIn('chart_of_account_types.name_pt_br', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)']);
            $expenseSubAccounts->where('chart_of_accounts.parent', '!=', 0);
            $expenseSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $expenseSubAccounts = $expenseSubAccounts->get()->toArray();
            $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();
            $printers = PosPrinter::where('created_by', \Auth::user()->creatorId())->with('branch')->orderBy('branch_id')->orderBy('type')->get();

            $kitComponents = ProductService::where('created_by', \Auth::user()->creatorId())
                ->where('type', 'product')
                ->orderBy('name')
                ->get();

            $optionProducts = ProductService::where('created_by', \Auth::user()->creatorId())
                ->where('type', 'product')
                ->orderBy('name')
                ->get();

            $ingredientProducts = ProductService::where('created_by', \Auth::user()->creatorId())
                ->where('type', 'ingredient')
                ->orderBy('name')
                ->get();
            $recipeItems = collect();

            return view('productservice.create', compact('category', 'unit', 'tax', 'customFields', 'incomeChartAccounts', 'incomeSubAccounts', 'expenseChartAccounts', 'expenseSubAccounts', 'priceLevels', 'printers', 'kitComponents', 'optionProducts', 'ingredientProducts', 'recipeItems'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {

        if (\Auth::user()->can('create product & service')) {

            $rules = [
                'name' => 'required',
                'sku' => $this->productSkuRules(),
                'sale_price' => 'required|numeric',
                'purchase_price' => 'required|numeric',
                'category_id' => 'required',
                'unit_id' => 'required',
                'type' => ['required', Rule::in(['product', 'service', 'kit', 'ingredient'])],
            ];
            if ($request->type === 'ingredient') {
                $rules['sale_price'] = 'nullable|numeric|min:0';
            }

            $validator = \Validator::make($request->all(), $rules, $this->productSkuMessages());

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->route('productservice.index')->with('error', $messages->first());
            }

            $productService                      = new ProductService();
            $productService->name                = $request->name;
            $productService->description         = $request->description;
            $productService->sku                 = $request->sku;
            $productService->shelf_no            = $request->shelf_no;
            $productService->row_no              = $request->row_no;
            $productService->sale_price          = $request->sale_price;
            $productService->sale_price_type      = in_array($request->sale_price_type, ['inclusive', 'exclusive'], true) ? $request->sale_price_type : 'exclusive';
            $productService->purchase_price      = $request->purchase_price;
            $productService->tax_id              = !empty($request->tax_id) ? implode(',', $request->tax_id) : '';
            $productService->unit_id             = $request->unit_id;
            $productService->unit_type           = $request->unit_type;
            $productService->price_level_id             = $request->price_level_id ?? null;
            $productService->quantity           = 0;
            $productService->type                       = $request->type;
            $productService->print_zone                 = $request->print_zone ?: null;
            $productService->pos_printer_id             = $request->filled('pos_printer_id') ? (int) $request->pos_printer_id : null;
            $productService->low_stock_threshold         = $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : null;
            $productService->max_stock_threshold         = $request->filled('max_stock_threshold') ? (int) $request->max_stock_threshold : null;
            $productService->affect_stock                = match ($request->type) {
                'kit' => false,
                'ingredient' => true,
                default => ! $request->boolean('does_not_affect_stock'),
            };
            if ($request->type === 'ingredient') {
                $productService->sale_price = $request->filled('sale_price') ? $request->sale_price : 0;
            }
            $productService->identifier_tracking          = $request->boolean('identifier_tracking');
            $productService->identifier_type              = in_array($request->identifier_type, ['serial', 'mac', 'both'], true) ? $request->identifier_type : 'serial';
            $productService->warranty_days                = $request->filled('warranty_days') ? (int) $request->warranty_days : null;
            $productService->expiry_alert_days            = $request->filled('expiry_alert_days') ? (int) $request->expiry_alert_days : null;
            self::syncBatchTrackingFields($productService, $request);
            $productService->sale_chartaccount_id       = $request->sale_chartaccount_id;
            $productService->expense_chartaccount_id    = $request->expense_chartaccount_id;
            $productService->category_id                = $request->category_id;

            if (!empty($request->pro_image)) {
                //storage limit
                $image_size = $request->file('pro_image')->getSize();
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                if ($result == 1) {
                    if ($productService->pro_image) {
                        $path = storage_path('uploads/pro_image' . $productService->pro_image);
                    }
                    $fileName = $request->pro_image->getClientOriginalName();
                    $productService->pro_image = $fileName;
                    $dir        = 'uploads/pro_image';
                    $path = Utility::upload_file($request, 'pro_image', $fileName, $dir, []);
                }
            }

            $productService->created_by       = \Auth::user()->creatorId();
            $productService->save();
            CustomField::saveData($productService, $request->customField);

            if ($productService->type === 'kit') {
                $components = $request->input('kit_components', []);
                if (is_array($components)) {
                    foreach ($components as $row) {
                        $componentId = (int) ($row['component_product_id'] ?? 0);
                        $qty = (float) ($row['quantity'] ?? 0);
                        if ($componentId <= 0 || $qty <= 0) {
                            continue;
                        }
                        $component = ProductService::where('id', $componentId)->where('created_by', \Auth::user()->creatorId())->first();
                        if (!$component || $component->type !== 'product' || $componentId === (int) $productService->id) {
                            continue;
                        }
                        ProductKitItem::create([
                            'kit_product_id' => (int) $productService->id,
                            'component_product_id' => (int) $componentId,
                            'quantity' => $qty,
                            'created_by' => (int) \Auth::user()->creatorId(),
                        ]);
                    }
                }
            }

            if ($productService->type === 'product') {
                $this->syncProductVariants($productService, $request);
                ProductOptionService::syncProductOptions($productService, $request);
            }
            $this->syncProductRecipes($productService, $request);

            $this->bumpProductServiceDatatableCountCache(\Auth::user()->creatorId());

            return redirect()->route('productservice.index')->with('success', __('Product successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show()
    {
        return redirect()->route('productservice.index');
    }

    public function edit($id)
    {
        $productService = ProductService::find($id);

        if (\Auth::user()->can('edit product & service')) {
            if ($productService->created_by == \Auth::user()->creatorId()) {
                $rawCats = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())
                    ->where('type', '=', 'product & service')
                    ->get(['id', 'name', 'parent_id']);
                $category = collect($this->buildCategoryOptions($rawCats));
                $units = ProductServiceUnit::where('created_by', '=', \Auth::user()->creatorId())->get();
                $unit = $units->pluck('name', 'id');
                $tax      = Tax::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');

                $productService->customField = CustomField::getData($productService, 'product');
                $customFields                = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'product')->get();
                $productService->tax_id      = explode(',', $productService->tax_id);
                $ulane = auth()->user()->lang ?? 'en';
                $name_column = match ($ulane) {
                    'fr' => 'name_fr',
                    'zh' => 'name_zh',
                    'es' => 'name_es',
                    'pt' => 'name_pt_br',
                    default => 'name',
                };
                $incomeChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name, chart_of_accounts.id as id'))
                    ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                    ->where('chart_of_account_types.name_pt_br', 'Rendimento')
                    ->where('parent', '=', 0)
                    ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                    ->pluck('code_name', 'id');
                $incomeChartAccounts->prepend('Select Account', 0);


                $incomeSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
                $incomeSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
                $incomeSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
                $incomeSubAccounts->where('chart_of_account_types.name_pt_br', 'Rendimento');
                $incomeSubAccounts->where('chart_of_accounts.parent', '!=', 0);
                $incomeSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                $incomeSubAccounts = $incomeSubAccounts->get()->toArray();


                $expenseChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name, chart_of_accounts.id as id'))
                    ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                    ->whereIn('chart_of_account_types.name_pt_br', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)'])
                    ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                    ->pluck('code_name', 'id');
                $expenseChartAccounts->prepend('Select Account', '');

                $expenseSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.' . $name_column . ') AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
                $expenseSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
                $expenseSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
                $expenseSubAccounts->whereIn('chart_of_account_types.name_pt_br', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)']);
                $expenseSubAccounts->where('chart_of_accounts.parent', '!=', 0);
                $expenseSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                $expenseSubAccounts = $expenseSubAccounts->get()->toArray();
                $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();
                $printers = PosPrinter::where('created_by', \Auth::user()->creatorId())->with('branch')->orderBy('branch_id')->orderBy('type')->get();

                $kitComponents = ProductService::where('created_by', \Auth::user()->creatorId())
                    ->where('type', 'product')
                    ->orderBy('name')
                    ->get();
                $kitItems = collect();
                if ($productService->type === 'kit') {
                    $kitItems = ProductKitItem::where('kit_product_id', $productService->id)
                        ->where('created_by', \Auth::user()->creatorId())
                        ->with(['component:id,name,sku,unit_id'])
                        ->orderBy('id')
                        ->get();
                }

                $productService->load(['variants', 'optionItems.optionProduct:id,name,sku,sale_price,purchase_price']);

                $optionItems = $productService->optionItems;
                $optionProducts = ProductService::where('created_by', \Auth::user()->creatorId())
                    ->where('type', 'product')
                    ->where('id', '!=', $productService->id)
                    ->orderBy('name')
                    ->get();

                $ingredientProducts = ProductService::where('created_by', \Auth::user()->creatorId())
                    ->where('type', 'ingredient')
                    ->orderBy('name')
                    ->get();
                $recipeItems = collect();
                if ($productService->type === 'product') {
                    $recipeItems = ProductRecipe::where('product_id', $productService->id)
                        ->where('created_by', \Auth::user()->creatorId())
                        ->with(['ingredientProduct:id,name,sku'])
                        ->orderBy('id')
                        ->get();
                }

                return view('productservice.edit', compact('category', 'unit', 'tax', 'productService', 'customFields', 'incomeChartAccounts', 'expenseChartAccounts', 'incomeSubAccounts', 'expenseSubAccounts', 'priceLevels', 'printers', 'kitItems', 'kitComponents', 'optionItems', 'optionProducts', 'ingredientProducts', 'recipeItems'));
            } else {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function kitItemStore(Request $request, $id)
    {
        if (!\Auth::user()->can('edit product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $kit = ProductService::where('id', $id)->where('created_by', $creatorId)->first();
        if (!$kit || $kit->type !== 'kit') {
            return redirect()->back()->with('error', __('Invalid kit product.'));
        }

        $request->validate([
            'component_product_id' => 'required|exists:product_services,id',
            'quantity' => 'required|numeric|min:0.0001',
        ]);

        $component = ProductService::where('id', $request->component_product_id)->where('created_by', $creatorId)->first();
        if (!$component || $component->type !== 'product') {
            return redirect()->back()->with('error', __('Component must be a product.'));
        }
        if ((int) $component->id === (int) $kit->id) {
            return redirect()->back()->with('error', __('Kit and component cannot be the same.'));
        }

        ProductKitItem::create([
            'kit_product_id' => (int) $kit->id,
            'component_product_id' => (int) $component->id,
            'quantity' => (float) $request->quantity,
            'created_by' => (int) $creatorId,
        ]);

        return redirect()->back()->with('success', __('Kit component added.'));
    }

    public function kitItemDestroy(Request $request, $id, ProductKitItem $kit_item)
    {
        if (!\Auth::user()->can('edit product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $kit = ProductService::where('id', $id)->where('created_by', $creatorId)->first();
        if (!$kit || $kit->type !== 'kit') {
            return redirect()->back()->with('error', __('Invalid kit product.'));
        }
        if ((int) $kit_item->created_by !== (int) $creatorId || (int) $kit_item->kit_product_id !== (int) $kit->id) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $kit_item->delete();
        return redirect()->back()->with('success', __('Kit component removed.'));
    }

    public function update(Request $request, $id)
    {

        if (\Auth::user()->can('edit product & service')) {
            $productService = ProductService::find($id);
            if ($productService->created_by == \Auth::user()->creatorId()) {
                $rules = [
                    'name' => 'required',
                    'sku' => $this->productSkuRules((int) $productService->id),
                    'sale_price' => 'required|numeric',
                    'purchase_price' => 'required|numeric',
                    'category_id' => 'required',
                    'unit_id' => 'required',
                    'type' => ['required', Rule::in(['product', 'service', 'kit', 'ingredient'])],

                ];
                if ($request->type === 'ingredient') {
                    $rules['sale_price'] = 'nullable|numeric|min:0';
                }

                $validator = \Validator::make($request->all(), $rules, $this->productSkuMessages());

                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->route('productservice.index')->with('error', $messages->first());
                }

                $productService->name           = $request->name;
                $productService->description    = $request->description;
                $productService->sku            = $request->sku;
                $productService->shelf_no       = $request->shelf_no;
                $productService->row_no         = $request->row_no;
                $productService->sale_price     = $request->sale_price;
                $productService->sale_price_type = in_array($request->sale_price_type, ['inclusive', 'exclusive'], true) ? $request->sale_price_type : 'exclusive';
                $productService->purchase_price = $request->purchase_price;
                $productService->tax_id         = !empty($request->tax_id) ? implode(',', $request->tax_id) : '';
                $productService->unit_id        = $request->unit_id;
                $productService->unit_type      = $request->unit_type;
                $productService->price_level_id             = $request->price_level_id ?? null;
                $productService->type                       = $request->type;
                $productService->print_zone                 = $request->print_zone ?: null;
                $productService->pos_printer_id             = $request->filled('pos_printer_id') ? (int) $request->pos_printer_id : null;
                $productService->low_stock_threshold        = $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : null;
                $productService->max_stock_threshold        = $request->filled('max_stock_threshold') ? (int) $request->max_stock_threshold : null;
                $productService->affect_stock                = match ($request->type) {
                'kit' => false,
                'ingredient' => true,
                default => ! $request->boolean('does_not_affect_stock'),
            };
            if ($request->type === 'ingredient') {
                $productService->sale_price = $request->filled('sale_price') ? $request->sale_price : 0;
            }
                $productService->identifier_tracking          = $request->boolean('identifier_tracking');
                $productService->identifier_type              = in_array($request->identifier_type, ['serial', 'mac', 'both'], true) ? $request->identifier_type : 'serial';
                $productService->warranty_days                = $request->filled('warranty_days') ? (int) $request->warranty_days : null;
                $productService->expiry_alert_days            = $request->filled('expiry_alert_days') ? (int) $request->expiry_alert_days : null;
                self::syncBatchTrackingFields($productService, $request);
                $productService->sale_chartaccount_id       = $request->sale_chartaccount_id;
                $productService->expense_chartaccount_id    = $request->expense_chartaccount_id;
                $productService->category_id                = $request->category_id;

                if (!empty($request->pro_image)) {
                    //storage limit
                    $file_path = '/uploads/pro_image/' . $productService->pro_image;
                    $image_size = $request->file('pro_image')->getSize();
                    $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                    if ($result == 1) {
                        if ($productService->pro_image) {
                            Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                            $path = storage_path('uploads/pro_image' . $productService->pro_image);

                        }
                        $fileName = $request->pro_image->getClientOriginalName();
                        $productService->pro_image = $fileName;
                        $dir        = 'uploads/pro_image';
                        $path = Utility::upload_file($request, 'pro_image', $fileName, $dir, []);
                    }
                }

                $productService->created_by     = \Auth::user()->creatorId();
                $productService->save();
                CustomField::saveData($productService, $request->customField);

                // Kit components sync (no nested forms in modal)
                $creatorId = \Auth::user()->creatorId();
                if ($productService->type === 'kit') {
                    $removeIds = $request->input('remove_kit_item_ids', []);
                    if (is_array($removeIds) && count($removeIds) > 0) {
                        ProductKitItem::where('created_by', $creatorId)
                            ->where('kit_product_id', $productService->id)
                            ->whereIn('id', array_map('intval', $removeIds))
                            ->delete();
                    }

                    $components = $request->input('kit_components', []);
                    if (is_array($components)) {
                        foreach ($components as $row) {
                            $componentId = (int) ($row['component_product_id'] ?? 0);
                            $qty = (float) ($row['quantity'] ?? 0);
                            if ($componentId <= 0 || $qty <= 0) {
                                continue;
                            }
                            $component = ProductService::where('id', $componentId)->where('created_by', $creatorId)->first();
                            if (!$component || $component->type !== 'product' || $componentId === (int) $productService->id) {
                                continue;
                            }
                            ProductKitItem::create([
                                'kit_product_id' => (int) $productService->id,
                                'component_product_id' => (int) $componentId,
                                'quantity' => $qty,
                                'created_by' => (int) $creatorId,
                            ]);
                        }
                    }
                } else {
                    // If switched away from kit, remove any existing kit lines
                    ProductKitItem::where('created_by', $creatorId)
                        ->where('kit_product_id', $productService->id)
                        ->delete();
                }

                if ($productService->type === 'product') {
                    $this->syncProductVariants($productService, $request);
                    ProductOptionService::syncProductOptions($productService, $request);
                } else {
                    ProductVariant::where('product_service_id', $productService->id)->delete();
                    ProductOptionItem::where('parent_product_id', $productService->id)->delete();
                    $productService->forceFill([
                        'variants_enabled' => false,
                        'variant_size_label' => null,
                        'variant_color_label' => null,
                        'options_enabled' => false,
                    ]);
                    $productService->save();
                }
                $this->syncProductRecipes($productService, $request);

                $this->bumpProductServiceDatatableCountCache(\Auth::user()->creatorId());

                return redirect()->route('productservice.index')->with('success', __('Product successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy($id)
    {
        if (\Auth::user()->can('delete product & service')) {
            $productService = ProductService::find($id);
            if ($productService->created_by == \Auth::user()->creatorId()) {
                if (!empty($productService->pro_image)) {
                    //storage limit
                    $file_path = '/uploads/pro_image/' . $productService->pro_image;
                    $result = Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                }

                $productService->delete();

                $this->bumpProductServiceDatatableCountCache(\Auth::user()->creatorId());

                return redirect()->route('productservice.index')->with('success', __('Product successfully deleted.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function export()
    {
        $name = 'product_service_' . date('Y-m-d i:h:s');
        $data = Excel::download(new ProductServiceExport(), $name . '.xlsx');

        return $data;
    }

    public function importFile()
    {
        return view('productservice.import');
    }

    public function productserviceImportdata(Request $request)
    {
        session_start();
        $html = '<h3 class="text-danger text-center">Below data is not inserted</h3></br>';
        $flag = 0;
        $html .= '<table class="table table-bordered"><tr>';
        try {
            $request = $request->data;
            $file_data = $_SESSION['file_data'];

            unset($_SESSION['file_data']);
        } catch (\Throwable $th) {
            $html = '<h3 class="text-danger text-center">Something went wrong, Please try again</h3></br>';
            return response()->json([
                'html' => true,
                'response' => $html,
            ]);
        }

        foreach ($file_data as $key => $row) {

            try {
                $productService = new ProductService();
                $productService->name = $row[$request['name']];
                $productService->sku = $row[$request['sku']];
                $productService->sale_price = $row[$request['sale_price']];
                $productService->purchase_price = $row[$request['purchase_price']];
                $productService->quantity = (isset($row[$request['type']]) && in_array($row[$request['type']], ['product', 'ingredient'], true)) ? $row[$request['quantity']] : 0;
                $productService->type = $row[$request['type']];
                $productService->description = $row[$request['description']];
                $productService->created_by = \Auth::user()->creatorId();
                $productService->save();

            } catch (\Exception $e) {
                $flag = 1;
                $html .= '<tr>';

                $html .= '<td>' . (isset($row[$request['name']]) ? $row[$request['name']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['sku']]) ? $row[$request['sku']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['sale_price']]) ? $row[$request['sale_price']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['purchase_price']]) ? $row[$request['purchase_price']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['quantity']]) ? $row[$request['quantity']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['tax_id']]) ? $row[$request['tax_id']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['category_id']]) ? $row[$request['category_id']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['unit_id']]) ? $row[$request['unit_id']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['type']]) ? $row[$request['type']] : '-') . '</td>';
                $html .= '<td>' . (isset($row[$request['description']]) ? $row[$request['description']] : '-') . '</td>';

                $html .= '</tr>';
            }
        }

        $html .= '
                </table>
                <br />
                ';

        if ($flag == 1) {

            return response()->json([
                'html' => true,
                'response' => $html,
            ]);
        } else {
            return response()->json([
                'html' => false,
                'response' => 'Data Imported Successfully',
            ]);
        }
    }

    public function warehouseDetail($id)
    {
        $products = WarehouseProduct::with(['warehouse'])->where('product_id', '=', $id)->where('created_by', '=', \Auth::user()->creatorId())->get();
        return view('productservice.detail', compact('products'));
    }

    public function searchProducts(Request $request)
    {
        $lastsegment = $request->session_key;

        if ((Auth::user()->can('manage pos') || Auth::user()->canManagePos()) && $request->ajax() && isset($lastsegment) && !empty($lastsegment)) {

            // Retail and Restaurant POS use the same product query (posList – all products by creator, no warehouse filter).
            $isPos = in_array($lastsegment, ['pos', 'pos-restaurant'], true);
            $page = max(1, (int) $request->get('page', 1));
            $perPage = 50;

            $cacheKey = null;
            if ($isPos) {
                $cacheKey = 'pos_products_html_' . Auth::user()->creatorId() . '_' . $lastsegment . '_' . ($request->war_id ?? '0') . '_' . ($request->cat_id ?? '0') . '_' . ($request->search ?? '') . '_' . ($request->type ?? 'name') . '_' . $page . '_' . self::POS_PRODUCT_HTML_CACHE_VER;
                $cachedHtml = Cache::get($cacheKey);
                if ($cachedHtml !== null) {
                    return response($cachedHtml)->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                }
            }

            $output = "";
            $runQuery = function () use ($request, $isPos, $page, $perPage) {
                if ($isPos) {
                    $q = ProductService::posList()->with(['unit:id,name']);
                    if ($request->cat_id !== '' && $request->search == '') {
                        $q = $this->applyPosCategoryFilter($q, $request->cat_id);
                    } else {
                        if ($request->search != '') {
                            $q = $q->where('product_services.' . $request->type, 'LIKE', "%{$request->search}%");
                        }
                        $q = $this->applyPosCategoryFilter($q, $request->cat_id);
                    }
                    $q = $q->limit($perPage)->offset(($page - 1) * $perPage);
                    return $q->get();
                }
                if ($request->war_id == '0') {
                    $ids = WarehouseProduct::where('warehouse_id', 1)->get()->pluck('product_id')->toArray();
                    if ($request->cat_id !== '' && $request->search == '') {
                        if ($request->cat_id == '0') {
                            return ProductService::getallproducts()->whereIn('product_services.id', $ids)->with(['unit'])->get();
                        }
                        return ProductService::getallproducts()->where('category_id', $request->cat_id)->whereIn('product_services.id', $ids)->with(['unit'])->get();
                    }
                    if ($request->cat_id == '0') {
                        return ProductService::getallproducts()->where('product_services.' . $request->type, 'LIKE', "%{$request->search}%")->with(['unit'])->get();
                    }
                    return ProductService::getallproducts()->where('product_services.' . $request->type, 'LIKE', "%{$request->search}%")->orWhere('category_id', $request->cat_id)->with(['unit'])->get();
                }
                $ids = WarehouseProduct::where('warehouse_id', $request->war_id)->get()->pluck('product_id')->toArray();
                if ($request->cat_id == '0') {
                    return ProductService::getallproducts()->whereIn('product_services.id', $ids)->with(['unit'])->get();
                }
                return ProductService::getallproducts()->whereIn('product_services.id', $ids)->where('category_id', $request->cat_id)->with(['unit'])->get();
            };

            $products = $runQuery();
            if ($isPos && $products->isNotEmpty()) {
                $eager = [
                    'category',
                    'variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ];
                if (Schema::hasTable('product_option_items') && Schema::hasColumn('product_services', 'options_enabled')) {
                    $eager['optionItems.optionProduct'] = fn ($q) => $q->select('id', 'name', 'sku', 'sale_price', 'purchase_price', 'type', 'options_enabled');
                }
                $products->load($eager);
            }

            // Build tax rate map in one query (avoid N+1)
            $taxRateMap = [];
            if (count($products) > 0) {
                $allTaxIds = [];
                foreach ($products as $product) {
                    if (!empty($product->tax_id)) {
                        foreach (array_filter(array_map('trim', explode(',', $product->tax_id))) as $tid) {
                            $allTaxIds[] = $tid;
                        }
                    }
                }
                $allTaxIds = array_unique(array_filter($allTaxIds));
                $taxModels = !empty($allTaxIds) ? Tax::whereIn('id', $allTaxIds)->get()->keyBy('id') : collect();
                foreach ($products as $product) {
                    $key = $product->tax_id ?? '';
                    if (!array_key_exists($key, $taxRateMap)) {
                        $rate = 0;
                        if (!empty($product->tax_id)) {
                            foreach (array_filter(array_map('trim', explode(',', $product->tax_id))) as $tid) {
                                $t = $taxModels->get($tid);
                                $rate += $t ? $t->rate : 0;
                            }
                        }
                        $taxRateMap[$key] = $rate;
                    }
                }
            }

            if (count($products) > 0) {
                $user = Auth::user();
                $priceFormatted = function ($price) use ($user) {
                    return $user->priceFormat($price);
                };
                $baseImgUrl = asset(Storage::url('uploads/pro_image/'));
                $addToCartBase = rtrim($request->getSchemeAndHttpHost(), '/') . '/add-to-cart';
                foreach ($products as $product) {
                    $isKitOrNoStock = (($product->type ?? null) === 'kit') || !($product->affect_stock ?? true);
                    $quantity = $isPos
                        ? ($isKitOrNoStock ? '—' : (int) $product->quantity)
                        : $product->warehouseProduct($product->id, $request->war_id != 0 ? $request->war_id : 7);
                    $unit = (!empty($product->unit)) ? $product->unit->name : '';
                    $imgFile = !empty($product->pro_image) ? $product->pro_image : 'default.png';
                    $imgSrc = rtrim($baseImgUrl, '/') . '/' . $imgFile;
                    if ($request->session_key == 'purchases') {
                        $productprice = $product->purchase_price != 0 ? $product->purchase_price : 0;
                    } elseif (in_array($request->session_key, ['pos', 'pos-restaurant'], true)) {
                        $productprice = $product->sale_price != 0 ? $product->sale_price : 0;
                    } else {
                        $productprice = $product->sale_price != 0 ? $product->sale_price : $product->purchase_price;
                    }
                    $taxRate = $taxRateMap[$product->tax_id ?? ''] ?? 0;
                    $printZone = PosPrintRoutingService::resolvePrintZoneForProduct($product);
                    $printerId = PosPrintRoutingService::resolvePosPrinterIdForProduct($product) ?? '';
                    $variantsPayload = '[]';
                    if ($isPos && ($product->variants_enabled ?? false) && $product->relationLoaded('variants') && $product->variants->isNotEmpty()) {
                        $variantsPayload = htmlspecialchars(json_encode($product->variants->map(function ($v) use ($product) {
                            return [
                                'id' => $v->id,
                                'label' => $v->displayLabel($product),
                                'sale_price' => $v->sale_price !== null ? (float) $v->sale_price : null,
                            ];
                        })->values()->all()), ENT_QUOTES, 'UTF-8');
                    }
                    $optionsPayload = '[]';
                    $optionsEnabledAttr = '0';
                    if ($isPos && ($product->options_enabled ?? false) && $product->relationLoaded('optionItems')) {
                        $optionsEnabledAttr = '1';
                        $opts = [];
                        foreach ($product->optionItems as $oi) {
                            $op = $oi->optionProduct;
                            if (! $op || ($op->type ?? '') !== 'product' || ($op->type ?? '') === 'ingredient' || ($op->options_enabled ?? false)) {
                                continue;
                            }
                            $opts[] = [
                                'product_id' => (int) $op->id,
                                'name' => $op->name,
                                'sku' => $op->sku,
                                'sale_price' => (float) ($op->sale_price ?? 0),
                                'purchase_price' => (float) ($op->purchase_price ?? 0),
                                'option_group' => $oi->option_group ? (string) $oi->option_group : null,
                                'is_required' => (bool) ($oi->is_required ?? false),
                            ];
                        }
                        $optionsPayload = htmlspecialchars(json_encode($opts, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    }
                    $dimUnit = $product->dimension_unit ?: ($product->unit->length_unit ?? 'cm');
                    $volDiv = $product->volumetric_divisor ?: ($product->unit->volumetric_divisor ?? '');
                    $output .= '<div class="' . self::POS_PRODUCT_CARD_COL_CLASS . '"><div class="tab-pane fade show active toacart w-100" data-url="' . $addToCartBase . '/' . $product->id . '/' . $lastsegment . '" data-product-id="' . $product->id . '" data-product-name="' . e($product->name) . '" data-product-price="' . $productprice . '" data-product-tax="' . $taxRate . '" data-product-image="' . e($imgSrc) . '" data-product-print-zone="' . e($printZone) . '" data-product-printer-id="' . e((string) $printerId) . '" data-variants-enabled="' . (($product->variants_enabled ?? false) ? '1' : '0') . '" data-variants=\'' . $variantsPayload . '\' data-options-enabled="' . $optionsEnabledAttr . '" data-options=\'' . $optionsPayload . '\' data-unit-type="' . e($product->unit_type ?? '') . '" data-dimension-unit="' . e($dimUnit) . '" data-volumetric-divisor="' . e((string) $volDiv) . '"><div class="position-relative card"><img loading="lazy" alt="" src="' . e($imgSrc) . '" class="card-image avatar shadow hover-shadow-lg" style="height:6rem;width:100%"><div class="p-0 custom-card-body card-body d-flex"><div class="card-body p-0 text-left card-bottom-content"><h5 class="mb-2 text-dark product-title-name">' . e($product->name) . '</h5><h6 class="mb-2 text-dark product-title-name small">' . e($product->sku) . '</h6><h5 class="">' . $priceFormatted($productprice) . '<small class="badge badge-danger mb-0 rounded qty_badge">' . $quantity . ' ' . e($unit) . '</small></h5><button type="button" class="btn btn-primary btn-sm rounded d-flex align-items-center gap-3 pos-add-cart-btn"><i class="ti ti-plus"></i> ' . __('Cart') . '</button></div></div></div></div></div>';
                }
                if ($cacheKey) {
                    Cache::put($cacheKey, $output, 90);
                }
                return response($output)->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            }

            $output = '<div class="card card-body col-12 text-center"><h5>' . __("No Product Available") . '</h5></div>';
            if ($isPos && $page > 1) {
                $output = '';
            }
            if ($cacheKey) {
                Cache::put($cacheKey, $output, 90);
            }
            return response($output)->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }
    }

    public function addToCart(Request $request, $id, $session_key)
    {

        $isPosSession = in_array($session_key, ['pos', 'pos-restaurant'], true);
            $canManage = Auth::user()->can('manage product & service') || ((Auth::user()->can('manage pos') || Auth::user()->can('pos restaurant waiter') || Auth::user()->can('pos restaurant full') || Auth::user()->can('pos restaurant manager')) && $isPosSession);
        if ($canManage && $request->ajax()) {
            $cartKey = $session_key;
            $tableKey = null;
            if ($session_key === 'pos-restaurant') {
                $reqCartKey = $request->get('cart_key');
                if ($reqCartKey && is_string($reqCartKey) && preg_match('/^(\d+_\d+|delivery_\d+|takeaway_\d+|pos_\d+)$/', $reqCartKey)) {
                    $cartKey = 'pos_restaurant_cart';
                    $tableKey = $reqCartKey;
                } else {
                    $tableId = (int) $request->get('table_id');
                    $shiftId = (int) $request->get('shift_id');
                    $orderType = $request->get('order_type');
                    if ($tableId && $shiftId) {
                        $cartKey = 'pos_restaurant_cart';
                        $tableKey = $tableId . '_' . $shiftId;
                    } elseif (in_array($orderType, ['delivery', 'takeaway'], true) && $shiftId) {
                        $cartKey = 'pos_restaurant_cart';
                        $tableKey = $orderType . '_' . $shiftId;
                    } else {
                        return response()->json(['code' => 404, 'status' => 'Error', 'error' => __('Select a table or order type (delivery/takeaway) and ensure shift is open.')], 404);
                    }
                }
            }
            $product = ProductService::with(['category', 'posPrinter'])->find($id);
            if ($product && ($product->type ?? '') === 'ingredient') {
                return response()->json([
                    'code' => 422,
                    'status' => 'Error',
                    'error' => __('Ingredient products cannot be sold on POS.'),
                ], 422);
            }
            $productquantity = 0;
            $variantId = $request->filled('product_variant_id') ? (int) $request->product_variant_id : null;
            if ($variantId !== null && $variantId <= 0) {
                $variantId = null;
            }
            $cartLineKey = $variantId ? $id . '_v_' . $variantId : (string) $id;
            $lineSuffix = trim((string) $request->get('line_suffix', ''));
            if ($lineSuffix !== '') {
                $cartLineKey .= '_m_' . preg_replace('/[^A-Za-z0-9_-]/', '', $lineSuffix);
            }

            if ($product) {
                if ($product->variants_enabled ?? false) {
                    if ($variantId === null) {
                        return response()->json(
                            [
                                'code' => 422,
                                'status' => 'Error',
                                'error' => __('Select size / color variant for this product.'),
                            ],
                            422
                        );
                    }
                    $variantRow = ProductVariant::where('id', $variantId)->where('product_service_id', $product->id)->first();
                    if (! $variantRow) {
                        return response()->json(
                            [
                                'code' => 404,
                                'status' => 'Error',
                                'error' => __('Invalid variant.'),
                            ],
                            404
                        );
                    }
                } elseif ($variantId !== null) {
                    $variantId = null;
                    $cartLineKey = (string) $id;
                }
            }

            $selectedOptions = [];
            $selectedOptionsJson = null;
            if ($product && ($product->options_enabled ?? false)) {
                try {
                    $selectedOptions = ProductOptionService::validateSelectedForParent(
                        (int) $product->id,
                        ProductOptionService::parseFromRequest($request),
                        (int) Auth::user()->creatorId(),
                        $session_key === 'purchases'
                    );
                } catch (\Throwable $e) {
                    return response()->json(
                        [
                            'code' => 422,
                            'status' => 'Error',
                            'error' => $e->getMessage(),
                        ],
                        422
                    );
                }
                $selectedOptionsJson = ProductOptionService::encodeOptions($selectedOptions);
                $cartLineKey .= ProductOptionService::optionsLineKeySuffix($selectedOptions);
            }

            if ($product) {
                if ($isPosSession) {
                    // Products that do not affect stock (e.g. composite dishes with BOM): allow sale without product-level quantity check
                    $affectStock = ($product->type === 'kit') ? false : ($product->affect_stock ?? true);
                    if (($product->options_enabled ?? false) && $selectedOptions !== []) {
                        $affectStock = false;
                    }
                    $warId = (int) ($request->war_id ?? 0);
                    if (($product->options_enabled ?? false) && $selectedOptions !== []) {
                        $minOpt = PHP_INT_MAX;
                        foreach ($selectedOptions as $optRow) {
                            $opt = ProductService::where('id', (int) $optRow['product_id'])->where('created_by', Auth::user()->creatorId())->first();
                            if (! $opt || ! ($opt->affect_stock ?? true)) {
                                continue;
                            }
                            $need = (float) ($optRow['quantity'] ?? 1);
                            $have = (int) floor((float) ($opt->quantity ?? 0));
                            $minOpt = min($minOpt, $need > 0 ? (int) floor($have / $need) : 0);
                        }
                        $productquantity = $minOpt === PHP_INT_MAX ? 999999 : max(0, $minOpt);
                    } elseif ($affectStock && ($product->variants_enabled ?? false)) {
                        $productquantity = (int) floor($product->variantStockInWarehouse($warId, $variantId));
                    } elseif ($affectStock && ! ($product->variants_enabled ?? false) && (($product->batch_tracking ?? false) || PosFefoDeductionService::hasLots((int) $product->id, $warId, (int) Auth::user()->creatorId()))) {
                        $productquantity = (int) floor(PosFefoDeductionService::availableQty((int) $product->id, $warId, (int) Auth::user()->creatorId()));
                    } else {
                        $productquantity = $affectStock ? (int) $product->quantity : 999999;
                    }
                } else {
                    $productquantity = $product->getTotalProductQuantity();
                }
            }

            if (!$product) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This Product is not found!'),
                    ],
                    404
                );
            }

            if ($isPosSession && $productquantity == 0) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            $productname = $product->name;
            $displayName = $productname;

            if ($session_key == 'purchases') {
                $productprice = $product->purchase_price != 0 ? $product->purchase_price : 0;
            } elseif ($isPosSession) {
                $productprice = $product->sale_price != 0 ? $product->sale_price : 0;
            } else {
                $productprice = $product->sale_price != 0 ? $product->sale_price : $product->purchase_price;
            }
            $measurementJson = $request->get('measurement_json');
            $measuredPrice = $request->get('measured_price');
            if ($isPosSession && is_numeric($measuredPrice) && (float) $measuredPrice > 0) {
                $productprice = (float) $measuredPrice;
            }

            if ($variantId !== null && $product) {
                $vrD = ProductVariant::where('id', $variantId)->where('product_service_id', $product->id)->first();
                if ($vrD) {
                    if ($session_key == 'purchases' && $vrD->purchase_price !== null && (float) $vrD->purchase_price >= 0) {
                        $productprice = (float) $vrD->purchase_price;
                    } elseif ($isPosSession && $vrD->sale_price !== null && (float) $vrD->sale_price >= 0) {
                        $productprice = (float) $vrD->sale_price;
                    }
                    $displayName = $productname . ' — ' . $vrD->displayLabel($product);
                }
            }

            if ($product && $selectedOptions !== []) {
                $productprice = ProductOptionService::calculateUnitPrice(
                    $product,
                    $selectedOptions,
                    $session_key === 'purchases'
                );
                $displayName = ProductOptionService::formatLineName($product, $selectedOptions);
            }

            $originalquantity = (int) $productquantity;

            $product_tax = '-';
            $product_tax_id = [];
            $totalTaxRate = 0;

            if ($isPosSession && $request->has('tax_rate') && is_numeric($request->tax_rate)) {
                $totalTaxRate = (float) $request->tax_rate;
                $product_tax = $request->get('tax_display', $totalTaxRate . '%');
                if (is_string($product_tax)) {
                    $product_tax = e($product_tax);
                }
                $product_tax_id = !empty($product->tax_id) ? array_filter(array_map('trim', explode(',', $product->tax_id))) : [];
            } else {
                $product_tax = '';
                if (!empty($product->tax_id)) {
                    $taxIds = array_filter(array_map('trim', explode(',', $product->tax_id)));
                    if (!empty($taxIds)) {
                        $taxModels = Tax::whereIn('id', $taxIds)->get();
                        foreach ($taxModels as $tax) {
                            $totalTaxRate += $tax->rate ?? 0;
                            $product_tax .= "<span class='badge bg-primary'>" . e($tax->name) . ' (' . $tax->rate . '%)' . "</span><br>";
                            $product_tax_id[] = $tax->id;
                        }
                    }
                }
                if ($product_tax === '') {
                    $product_tax = '-';
                }
            }
            $producttax = $totalTaxRate;

            $priceType = in_array($product->sale_price_type ?? 'exclusive', ['inclusive', 'exclusive'], true) ? $product->sale_price_type : 'exclusive';
            if ($priceType === 'inclusive' && $producttax > 0) {
                $subtotal = $productprice;
                $tax = $productprice - ($productprice / (1 + $producttax / 100));
            } else {
                $tax = ($productprice * $producttax) / 100;
                $subtotal = $productprice + $tax;
            }

            if ($tableKey !== null) {
                $all = session()->get($cartKey, []);
                $cart = $all[$tableKey] ?? null;
            } else {
                $cart = session()->get($cartKey);
            }
            $image_url = !empty($product->pro_image) ? 'uploads/pro_image/' . $product->pro_image : 'uploads/pro_image/default.png';
            $measurementJsonEsc = is_string($measurementJson) ? e($measurementJson) : '';
            $selectedOptionsAttr = $selectedOptionsJson ? e($selectedOptionsJson) : '';

            $model_delete_id = 'delete-form-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $cartLineKey);

            $carthtml = '';
            $sku = $product->sku ?? '';
            $catName = $product->category ? $product->category->name : '';
            $taxDisplay = str_replace("'", "&#39;", strip_tags($product_tax));
            $printZone = PosPrintRoutingService::resolvePrintZoneForProduct($product);
            $printerId = PosPrintRoutingService::resolvePosPrinterIdForProduct($product);
            $printerName = '';
            if ($printerId) {
                $resolvedPrinter = PosPrinter::find($printerId);
                $printerName = $resolvedPrinter ? e($resolvedPrinter->name) : '';
            } elseif ($product->posPrinter) {
                $printerId = $product->posPrinter->id;
                $printerName = e($product->posPrinter->name);
            }
            $identifierTracking = (bool) ($product->identifier_tracking ?? false);
            if ($isPosSession) {
                if ($request->get('restaurant') || $session_key === 'pos-restaurant') {
                    $line = (object) [
                        'id' => $id,
                        'cart_line_key' => $cartLineKey,
                        'line_id' => '',
                        'name' => $displayName,
                        'quantity' => 1,
                        'price' => $productprice,
                        'tax' => $producttax,
                        'description' => '',
                        'is_sent' => false,
                        'status' => 'not_sent',
                        'print_zone' => $printZone,
                        'pos_printer_id' => $printerId,
                        'printer_name' => $product->posPrinter ? $product->posPrinter->name : '',
                    ];
                    $carthtml .= view('pos.partials.restaurant-cart-row', [
                        'line' => $line,
                        'rowId' => 'product-id-r-' . str_replace('_', '-', $cartLineKey),
                        'rowClass' => '',
                        'subtotal' => $subtotal,
                        'taxDisplay' => $taxDisplay,
                        'statusText' => __('pos.status_not_sent'),
                        'sessionKeyVal' => $tableKey ?? '',
                        'deleteFormId' => $model_delete_id,
                        'canShowVoid' => false,
                    ])->render();
                } else {
                $carthtml .= '<div class="pos-cart-card-item card border rounded-2 mb-2 shadow-sm" data-product-id="' . $id . '" data-product-variant-id="' . (int) ($variantId ?? 0) . '" data-cart-line-key="' . e($cartLineKey) . '" id="product-id-' . e(str_replace('_', '-', $cartLineKey)) . '" data-name="' . e($displayName) . '" data-price="' . $productprice . '" data-quantity="1" data-tax="' . $producttax . '" data-subtotal="' . $subtotal . '" data-discount="0" data-netprice="' . $subtotal . '" data-print-zone="' . e($printZone) . '" data-printer-id="' . $printerId . '" data-printer-name="' . $printerName . '" data-measurement-json="' . $measurementJsonEsc . '">';
                $carthtml .= '<div class="card-body p-2">';
                $carthtml .= '<div class="d-flex justify-content-between align-items-start gap-2">';
                $carthtml .= '<div class="flex-grow-1 min-w-0"><h6 class="mb-0 text-dark fw-bold text-truncate name">' . e($displayName) . '</h6>';
                if ($sku) {
                    $carthtml .= '<small class="text-muted d-block">SKU: ' . e($sku) . '</small>';
                }
                if ($catName) {
                    $carthtml .= '<span class="badge bg-light text-dark border mt-1">' . e($catName) . '</span>';
                }
                $carthtml .= '</div>';
                $carthtml .= '<div class="action-btn"><a href="#" class="btn btn-sm btn-danger p-1 bs-pass-para-pos" data-confirm="' . e(__('Are You Sure?')) . '" data-text="' . e(__('This action can not be undone. Do you want to continue?')) . '" data-confirm-yes="' . $model_delete_id . '" title="' . __('Delete') . '" data-id="' . e($cartLineKey) . '"><i class="ti ti-trash"></i></a>';
                $carthtml .= '<form method="post" action="' . url('remove-from-cart') . '" accept-charset="UTF-8" id="' . $model_delete_id . '"><input name="_method" type="hidden" value="DELETE"><input name="_token" type="hidden" value="' . csrf_token() . '"><input type="hidden" name="session_key" value="' . $session_key . '"><input type="hidden" name="id" value="' . e($cartLineKey) . '"></form></div></div>';
                $carthtml .= '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">';
                $carthtml .= '<span class="quantity buttons_added d-inline-flex align-items-center border rounded"><input type="button" value="-" class="minus btn btn-sm btn-outline-secondary border-0 py-0"><input type="number" step="1" min="1" max="" name="quantity" title="' . __('Quantity') . '" class="input-number form-control form-control-sm border-0 text-center" style="width: 2.5rem;" data-url="' . url('update-cart/') . '" data-id="' . e($cartLineKey) . '" value="1"><input type="button" value="+" class="plus btn btn-sm btn-outline-secondary border-0 py-0"></span>';
                $carthtml .= '<div class="text-end"><div class="fw-bold text-dark subtotal">' . Auth::user()->priceFormat($subtotal) . '</div><small class="text-muted price">' . Auth::user()->priceFormat($productprice) . ' ' . __('each') . '</small></div></div>';
                $carthtml .= '<small class="text-muted d-block mt-1 tax">' . __('Tax') . ': ' . $taxDisplay . '</small>';
                if ($identifierTracking) {
                    $carthtml .= '<label class="form-label small mb-0 mt-2">' . e(__('Serial / MAC')) . '</label>';
                    $carthtml .= '<textarea class="form-control form-control-sm pos-line-identifiers" rows="2" data-product-id="' . (int) $id . '" placeholder="' . e(__('One per line or comma-separated — must match quantity')) . '"></textarea>';
                }
                $carthtml .= '</div></div>';
                }
            } else {
                $carthtml .= '<tr data-product-id="' . $id . '" data-product-variant-id="' . (int) ($variantId ?? 0) . '" data-cart-line-key="' . e($cartLineKey) . '" id="product-id-' . e(str_replace('_', '-', $cartLineKey)) . '">
                            <td class="cart-images">
                                <img alt="Image placeholder" src="' . asset(Storage::url($image_url)) . '" class="card-image avatar shadow hover-shadow-lg">
                            </td>
                            <td class="name">' . e($displayName) . '</td>
                            <td class="">
                                   <span class="quantity buttons_added">
                                         <input type="button" value="-" class="minus">
                                         <input type="number" step="1" min="1" max="" name="quantity" title="' . __('Quantity') . '" class="input-number" size="4" value="1" data-url="' . url('update-cart/') . '" data-id="' . e($cartLineKey) . '">
                                         <input type="button" value="+" class="plus">
                                   </span>
                            </td>
                            <td class="tax">' . $product_tax . '</td>
                            <td class="price">' . Auth::user()->priceFormat($productprice) . '</td>
                            <td class="subtotal">' . Auth::user()->priceFormat($subtotal) . '</td>
                            <td class="action-btn mt-3">
                                 <a href="#" class="btn btn-sm bg-danger bs-pass-para-pos" data-confirm="' . __("Are You Sure?") . '" data-text="' . __("This action can not be undone. Do you want to continue?") . '" data-confirm-yes=' . $model_delete_id . ' title="' . __('Delete') . '" data-id="' . e($cartLineKey) . '">
                                   <span class=""><i class="ti ti-trash text-white"></i></span>
                                 </a>
                                 <form method="post" action="' . url('remove-from-cart') . '"  accept-charset="UTF-8" id="' . $model_delete_id . '">
                                      <input name="_method" type="hidden" value="DELETE">
                                      <input name="_token" type="hidden" value="' . csrf_token() . '">
                                      <input type="hidden" name="session_key" value="' . $session_key . '">
                                      <input type="hidden" name="id" value="' . e($cartLineKey) . '">
                                 </form>
                            </td>
                        </tr>';
            }

            $linePayload = [
                'name' => $displayName,
                'quantity' => 1,
                'price' => $productprice,
                'id' => $id,
                'cart_line_key' => $cartLineKey,
                'product_variant_id' => $variantId,
                'tax' => $producttax,
                'subtotal' => $subtotal,
                'originalquantity' => $originalquantity,
                'product_tax' => $product_tax,
                'product_tax_id' => ! empty($product_tax_id) ? implode(',', $product_tax_id) : 0,
                'price_type' => $priceType,
                'identifier_tracking' => $identifierTracking,
                'identifiers' => '',
                'measurement_json' => is_string($measurementJson) ? $measurementJson : null,
                'selected_options' => $selectedOptions,
                'selected_options_json' => $selectedOptionsJson,
                'print_zone' => $printZone,
                'pos_printer_id' => $printerId ?: null,
                'printer_name' => $printerName ? strip_tags(html_entity_decode($printerName)) : '',
            ];

            // if cart is empty then this the first product
            if (! $cart) {
                $cart = [
                    $cartLineKey => $linePayload,
                ];

                if ($originalquantity < $cart[$cartLineKey]['quantity'] && $isPosSession) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }

                if ($tableKey !== null) {
                    $all = session()->get($cartKey, []);
                    $all[$tableKey] = $cart;
                    session()->put($cartKey, $all);
                } else {
                    session()->put($session_key, $cart);
                }

                return response()->json(
                    [
                        'code' => 200,
                        'status' => 'Success',
                        'success' => $displayName . __(' added to cart successfully!'),
                        'product' => $cart[$cartLineKey],
                        'carthtml' => $carthtml,
                    ]
                );
            }

            // if cart not empty then check if this line exists then increment quantity
            if (isset($cart[$cartLineKey])) {
                $cart[$cartLineKey]['quantity']++;
                $cart[$cartLineKey]['id'] = $id;
                $cart[$cartLineKey]['product_variant_id'] = $variantId;
                if (! empty($cart[$cartLineKey]['identifier_tracking'] ?? false)) {
                    $cart[$cartLineKey]['identifiers'] = '';
                }
                if (! isset($cart[$cartLineKey]['price_type'])) {
                    $cart[$cartLineKey]['price_type'] = $priceType;
                }
                $pt = $cart[$cartLineKey]['price_type'] ?? 'exclusive';
                $qty = $cart[$cartLineKey]['quantity'];
                $pr = $cart[$cartLineKey]['price'];
                $tx = $cart[$cartLineKey]['tax'] ?? 0;
                if ($pt === 'inclusive' && $tx > 0) {
                    $cart[$cartLineKey]['subtotal'] = $pr * $qty;
                } else {
                    $subtotal = $pr * $qty;
                    $cart[$cartLineKey]['subtotal'] = $subtotal * (1 + $tx / 100);
                }
                $cart[$cartLineKey]['originalquantity'] = $originalquantity;

                if ($originalquantity < $cart[$cartLineKey]['quantity'] && $isPosSession) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }

                if ($tableKey !== null) {
                    $all = session()->get($cartKey, []);
                    $all[$tableKey] = $cart;
                    session()->put($cartKey, $all);
                } else {
                    session()->put($session_key, $cart);
                }

                return response()->json(
                    [
                        'code' => 200,
                        'status' => 'Success',
                        'success' => $displayName . __(' added to cart successfully!'),
                        'product' => $cart[$cartLineKey],
                        'carttotal' => $cart,
                    ]
                );
            }

            // if item not exist in cart then add to cart with quantity = 1
            $cart[$cartLineKey] = $linePayload;

            if ($originalquantity < $cart[$cartLineKey]['quantity'] && $isPosSession) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            if ($tableKey !== null) {
                $all = session()->get($cartKey, []);
                $all[$tableKey] = $cart;
                session()->put($cartKey, $all);
            } else {
                session()->put($session_key, $cart);
            }

            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => $displayName . __(' added to cart successfully!'),
                    'product' => $cart[$cartLineKey],
                    'carthtml' => $carthtml,
                    'carttotal' => $cart,
                ]
            );
        } else {
            $msg = !$request->ajax()
                ? __('This endpoint must be called via AJAX from POS.')
                : __('This Product is not found!');
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => $msg,
                ],
                404
            );
        }
    }

    public function updateCart(Request $request)
    {

        $lineKey = (string) $request->id;
        $quantity    = $request->quantity;
        $discount    = $request->discount;
        $session_key = $request->session_key;

        $isPosSession = in_array($session_key, ['pos', 'pos-restaurant'], true);
            $canManage = Auth::user()->can('manage product & service') || ((Auth::user()->can('manage pos') || Auth::user()->can('pos restaurant waiter') || Auth::user()->can('pos restaurant full') || Auth::user()->can('pos restaurant manager')) && $isPosSession);
        if ($canManage && $request->ajax() && $lineKey !== '' && isset($session_key) && !empty($session_key)) {
            $cartKey = $session_key;
            $tableKey = null;
            if ($session_key === 'pos-restaurant') {
                $reqCartKey = $request->get('cart_key');
                if ($reqCartKey && is_string($reqCartKey) && preg_match('/^(\d+_\d+|delivery_\d+|takeaway_\d+|pos_\d+)$/', $reqCartKey)) {
                    $tableKey = $reqCartKey;
                } else {
                    $tableId = (int) $request->get('table_id');
                    $shiftId = (int) $request->get('shift_id');
                    $orderType = $request->get('order_type');
                    if ($tableId && $shiftId) {
                        $tableKey = $tableId . '_' . $shiftId;
                    } elseif (in_array($orderType, ['delivery', 'takeaway'], true) && $shiftId) {
                        $tableKey = $orderType . '_' . $shiftId;
                    }
                }
                if ($tableKey) {
                    $cartKey = 'pos_restaurant_cart';
                } else {
                    return response()->json(['code' => 404, 'status' => 'Error', 'error' => __('Select a table or order type and ensure shift is open.')], 404);
                }
            }
            if ($tableKey !== null) {
                $all = session()->get($cartKey, []);
                $cart = $all[$tableKey] ?? [];
            } else {
                $cart = session()->get($cartKey);
            }
            if (!is_array($cart)) {
                $cart = [];
            }

            if (isset($cart[$lineKey]) && $quantity == 0) {
                unset($cart[$lineKey]);
            }

            if ($quantity) {

                $cart[$lineKey]['quantity'] = $quantity;

                $producttax   = isset($cart[$lineKey]) ? $cart[$lineKey]['tax'] : 0;
                $productprice = $cart[$lineKey]['price'];
                $priceType    = $cart[$lineKey]['price_type'] ?? 'exclusive';

                if ($priceType === 'inclusive' && $producttax > 0) {
                    $cart[$lineKey]['subtotal'] = $productprice * $quantity;
                } else {
                    $subtotal = $productprice * $quantity;
                    $tax = ($subtotal * $producttax) / 100;
                    $cart[$lineKey]['subtotal'] = $subtotal + $tax;
                }
            }

            // POS: validate against product's quantity (stock); skip for products that do not affect stock (e.g. BOM dishes)
            if (isset($cart[$lineKey]) && $isPosSession) {
                $line = $cart[$lineKey];
                $productId = (int) ($line['id'] ?? 0);
                $variantLineId = isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : 0;
                $variantLineId = $variantLineId > 0 ? $variantLineId : null;
                $product = $productId > 0 ? ProductService::find($productId) : null;
                $affectStock = $product ? (($product->type === 'kit') ? false : ($product->affect_stock ?? true)) : true;
                $warehouseId = (int) ($request->war_id ?? 0);
                $creatorId = (int) Auth::user()->creatorId();
                if ($product && $affectStock && ($product->variants_enabled ?? false) && $variantLineId) {
                    $maxQty = (int) floor($product->variantStockInWarehouse($warehouseId, $variantLineId));
                } elseif ($product && $affectStock && ($product->variants_enabled ?? false) && ! $variantLineId) {
                    $maxQty = (int) floor($product->variantStockInWarehouse($warehouseId, null));
                } elseif ($product && $affectStock && (($product->batch_tracking ?? false) || PosFefoDeductionService::hasLots((int) $product->id, $warehouseId, $creatorId))) {
                    $maxQty = (int) floor(PosFefoDeductionService::availableQty((int) $product->id, $warehouseId, $creatorId));
                } else {
                    $maxQty = $product ? ($affectStock ? (int) $product->quantity : 999999) : 0;
                }
                if ($cart[$lineKey]['quantity'] > $maxQty) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }
                $cart[$lineKey]['originalquantity'] = $maxQty;
            }

            if ($request->has('identifiers') && isset($cart[$lineKey])) {
                $cart[$lineKey]['identifiers'] = (string) $request->input('identifiers', '');
            }

            $subtotal = array_sum(array_column($cart, 'subtotal'));
            $discount = $request->discount;
            $total = $subtotal - $discount;

            $totalDiscount = Auth::user()->priceFormat($total);
            $discount = $totalDiscount;

            if ($tableKey !== null) {
                $all = session()->get($cartKey, []);
                $all[$tableKey] = $cart;
                session()->put($cartKey, $all);
            } else {
                session()->put($session_key, $cart);
            }

            return response()->json(
                [
                    'code' => 200,
                    'success' => __('Cart updated successfully!'),
                    'product' => $cart,
                    'discount' => $discount,
                ]
            );
        } else {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This Product is not found!'),
                ],
                404
            );
        }
    }

    public function emptyCart(Request $request)
    {
        $session_key = $request->session_key;

        $isPosSession = in_array($session_key, ['pos', 'pos-restaurant'], true);
            $canManage = Auth::user()->can('manage product & service') || ((Auth::user()->can('manage pos') || Auth::user()->can('pos restaurant waiter') || Auth::user()->can('pos restaurant full') || Auth::user()->can('pos restaurant manager')) && $isPosSession);
        if ($canManage && isset($session_key) && !empty($session_key)) {
            if ($session_key === 'pos-restaurant') {
                $reqCartKey = $request->get('cart_key');
                if ($reqCartKey && is_string($reqCartKey)) {
                    $cartKey = 'pos_restaurant_cart';
                    $all = session()->get($cartKey, []);
                    if (isset($all[$reqCartKey])) {
                        unset($all[$reqCartKey]);
                        session()->put($cartKey, $all);
                    }
                } else {
                    $tableId = (int) $request->get('table_id');
                    $shiftId = (int) $request->get('shift_id');
                    if ($tableId && $shiftId) {
                        $cartKey = 'pos_restaurant_cart';
                        $all = session()->get($cartKey, []);
                        $tableKey = $tableId . '_' . $shiftId;
                        if (isset($all[$tableKey])) {
                            unset($all[$tableKey]);
                            session()->put($cartKey, $all);
                        }
                    }
                }
            } else {
                $cart = session()->get($session_key);
                if (isset($cart) && count($cart) > 0) {
                    session()->forget($session_key);
                }
            }

            return redirect()->back()->with('error', __('Cart is empty!'));
        } else {
            return redirect()->back()->with('error', __('Cart cannot be empty!.'));
        }
    }

    public function warehouseemptyCart(Request $request)
    {
        $session_key = $request->session_key;

        if ($session_key === 'pos-restaurant') {
            $reqCartKey = $request->get('cart_key');
            if ($reqCartKey && is_string($reqCartKey)) {
                $cartKey = 'pos_restaurant_cart';
                $all = session()->get($cartKey, []);
                if (isset($all[$reqCartKey])) {
                    unset($all[$reqCartKey]);
                    session()->put($cartKey, $all);
                }
            } else {
                $tableId = (int) $request->get('table_id');
                $shiftId = (int) $request->get('shift_id');
                if ($tableId && $shiftId) {
                    $cartKey = 'pos_restaurant_cart';
                    $all = session()->get($cartKey, []);
                    $tableKey = $tableId . '_' . $shiftId;
                    if (isset($all[$tableKey])) {
                        unset($all[$tableKey]);
                        session()->put($cartKey, $all);
                    }
                }
            }
        } else {
            $cart = session()->get($session_key);
            if (isset($cart) && count($cart) > 0) {
                session()->forget($session_key);
            }
        }

        return response()->json();
    }

    public function removeFromCart(Request $request)
    {
        $id          = $request->id;
        $session_key = $request->session_key;
        $isPosSession = in_array($session_key, ['pos', 'pos-restaurant'], true);
            $canManage = Auth::user()->can('manage product & service') || ((Auth::user()->can('manage pos') || Auth::user()->can('pos restaurant waiter') || Auth::user()->can('pos restaurant full') || Auth::user()->can('pos restaurant manager')) && $isPosSession);
        if ($canManage && isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cartKey = $session_key;
            $tableKey = null;
            if ($session_key === 'pos-restaurant') {
                $reqCartKey = $request->get('cart_key');
                if ($reqCartKey && is_string($reqCartKey) && preg_match('/^(\d+_\d+|delivery_\d+|takeaway_\d+|pos_\d+)$/', $reqCartKey)) {
                    $tableKey = $reqCartKey;
                } else {
                    $tableId = (int) $request->get('table_id');
                    $shiftId = (int) $request->get('shift_id');
                    $orderType = $request->get('order_type');
                    if ($tableId && $shiftId) {
                        $tableKey = $tableId . '_' . $shiftId;
                    } elseif (in_array($orderType, ['delivery', 'takeaway'], true) && $shiftId) {
                        $tableKey = $orderType . '_' . $shiftId;
                    }
                }
                if ($tableKey) {
                    $cartKey = 'pos_restaurant_cart';
                } else {
                    return redirect()->back()->with('error', __('Select a table or order type and ensure shift is open.'));
                }
            }
            if ($tableKey !== null) {
                $all = session()->get($cartKey, []);
                $cart = $all[$tableKey] ?? [];
            } else {
                $cart = session()->get($cartKey);
            }
            if (is_array($cart) && isset($cart[$id])) {
                unset($cart[$id]);
                if ($tableKey !== null) {
                    $all = session()->get($cartKey, []);
                    $all[$tableKey] = $cart;
                    session()->put($cartKey, $all);
                } else {
                    session()->put($session_key, $cart);
                }
            }

            return redirect()->back()->with('error', __('Product removed from cart!'));
        } else {
            return redirect()->back()->with('error', __('This Product is not found!'));
        }
    }

    protected function syncProductRecipes(ProductService $productService, Request $request): void
    {
        $creatorId = (int) Auth::user()->creatorId();

        if ($productService->type !== 'product') {
            ProductRecipe::where('product_id', $productService->id)->where('created_by', $creatorId)->delete();

            return;
        }

        $removeIds = $request->input('remove_recipe_ids', []);
        if (is_array($removeIds) && count($removeIds) > 0) {
            ProductRecipe::where('product_id', $productService->id)
                ->where('created_by', $creatorId)
                ->whereIn('id', array_map('intval', $removeIds))
                ->delete();
        }

        $rows = $request->input('product_recipes', []);
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $ingredientId = (int) ($row['ingredient_product_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            if ($ingredientId <= 0 || $qty <= 0 || $ingredientId === (int) $productService->id) {
                continue;
            }

            $ingredient = ProductService::where('id', $ingredientId)
                ->where('created_by', $creatorId)
                ->where('type', 'ingredient')
                ->first();
            if (! $ingredient) {
                continue;
            }

            $exists = ProductRecipe::where('product_id', $productService->id)
                ->where('ingredient_product_id', $ingredientId)
                ->exists();
            if ($exists) {
                continue;
            }

            ProductRecipe::create([
                'product_id' => (int) $productService->id,
                'ingredient_product_id' => $ingredientId,
                'quantity' => $qty,
                'unit_id' => ! empty($row['unit_id']) ? (int) $row['unit_id'] : null,
                'created_by' => $creatorId,
            ]);
        }
    }

    protected function syncProductVariants(ProductService $productService, Request $request): void
    {
        $creatorId = (int) Auth::user()->creatorId();
        if ($productService->type !== 'product') {
            return;
        }

        $productService->variants_enabled = $request->boolean('variants_enabled');
        $productService->variant_size_label = $request->filled('variant_size_label') ? mb_substr(trim((string) $request->variant_size_label), 0, 64) : null;
        $productService->variant_color_label = $request->filled('variant_color_label') ? mb_substr(trim((string) $request->variant_color_label), 0, 64) : null;
        $productService->unit_volume_cbm = $request->filled('unit_volume_cbm') ? $request->input('unit_volume_cbm') : null;
        $productService->unit_area_sqm = $request->filled('unit_area_sqm') ? $request->input('unit_area_sqm') : null;
        $productService->unit_length = $request->filled('unit_length') ? $request->input('unit_length') : null;
        $productService->unit_width = $request->filled('unit_width') ? $request->input('unit_width') : null;
        $productService->unit_height = $request->filled('unit_height') ? $request->input('unit_height') : null;
        $productService->dimension_unit = $request->filled('dimension_unit') ? $request->input('dimension_unit') : null;
        $productService->volumetric_divisor = $request->filled('volumetric_divisor') ? (int) $request->input('volumetric_divisor') : null;
        $productService->unit_volumetric_weight = $request->filled('unit_volumetric_weight') ? $request->input('unit_volumetric_weight') : null;
        $productService->save();

        if (! $productService->variants_enabled) {
            ProductVariant::where('product_service_id', $productService->id)->delete();

            return;
        }

        $rows = $request->input('variant_rows', []);
        if (! is_array($rows)) {
            $rows = [];
        }
        $seenIds = [];
        $sort = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $size = mb_substr(trim((string) ($row['size'] ?? '')), 0, 64);
            $color = mb_substr(trim((string) ($row['color'] ?? '')), 0, 64);
            $sku = mb_substr(trim((string) ($row['sku'] ?? '')), 0, 191);
            $salePrice = array_key_exists('sale_price', $row) && $row['sale_price'] !== '' && $row['sale_price'] !== null
                ? round((float) $row['sale_price'], 2) : null;
            $purchasePrice = array_key_exists('purchase_price', $row) && $row['purchase_price'] !== '' && $row['purchase_price'] !== null
                ? round((float) $row['purchase_price'], 2) : null;

            if ($size === '' && $color === '') {
                continue;
            }
            if ($sku === '') {
                $sku = $productService->sku . '-v' . ($sort + 1);
            }

            $rowId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($rowId > 0) {
                $existing = ProductVariant::where('id', $rowId)
                    ->where('product_service_id', $productService->id)
                    ->where('created_by', $creatorId)
                    ->first();
                if ($existing) {
                    $existing->update([
                        'size' => $size,
                        'color' => $color,
                        'sku' => $sku,
                        'sale_price' => $salePrice,
                        'purchase_price' => $purchasePrice,
                        'sort_order' => $sort,
                    ]);
                    $seenIds[] = $existing->id;
                    $sort++;

                    continue;
                }
            }

            $nv = ProductVariant::create([
                'product_service_id' => $productService->id,
                'size' => $size,
                'color' => $color,
                'sku' => $sku,
                'sale_price' => $salePrice,
                'purchase_price' => $purchasePrice,
                'sort_order' => $sort,
                'created_by' => $creatorId,
            ]);
            $seenIds[] = $nv->id;
            $sort++;
        }

        if ($seenIds === []) {
            ProductVariant::where('product_service_id', $productService->id)
                ->where('created_by', $creatorId)
                ->delete();
        } else {
            ProductVariant::where('product_service_id', $productService->id)
                ->where('created_by', $creatorId)
                ->whereNotIn('id', $seenIds)
                ->delete();
        }
    }

    public function optionsJson(ProductService $productservice)
    {
        if ((int) $productservice->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }

        return response()->json(ProductOptionService::productJsonExtras($productservice));
    }

    public function variantsJson(ProductService $productservice)
    {
        if ((int) $productservice->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }
        $productservice->load('variants');

        return response()->json([
            'variants_enabled' => (bool) $productservice->variants_enabled,
            'labels' => [
                'size' => $productservice->variant_size_label,
                'color' => $productservice->variant_color_label,
            ],
            'unit_volume_cbm' => $productservice->unit_volume_cbm,
            'unit_area_sqm' => $productservice->unit_area_sqm,
            'variants' => $productservice->variants->map(function ($v) use ($productservice) {
                return [
                    'id' => $v->id,
                    'size' => $v->size,
                    'color' => $v->color,
                    'sku' => $v->sku,
                    'label' => $v->displayLabel($productservice),
                    'sale_price' => $v->sale_price,
                    'purchase_price' => $v->purchase_price,
                ];
            })->values(),
        ]);
    }

    private static function syncBatchTrackingFields(ProductService $productService, Request $request): void
    {
        $stockTypes = ['product', 'ingredient'];
        if (! in_array($productService->type ?? $request->type, $stockTypes, true)) {
            $productService->batch_tracking = false;
            $productService->batch_consumption_method = BatchLotDeductionService::METHOD_FEFO;

            return;
        }

        $productService->batch_tracking = $request->boolean('batch_tracking');
        $method = BatchLotDeductionService::normalizeMethod($request->input('batch_consumption_method'));
        $productService->batch_consumption_method = $productService->batch_tracking
            ? $method
            : BatchLotDeductionService::METHOD_FEFO;
    }

    /**
     * Suggest the next unique numeric SKU for the current company.
     */
    public function generateSku()
    {
        if (! \Auth::user()->can('create product & service') && ! \Auth::user()->can('edit product & service')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        return response()->json([
            'sku' => $this->nextAvailableSku((int) \Auth::user()->creatorId()),
        ]);
    }

    private function productSkuRules(?int $ignoreId = null): array
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $unique = Rule::unique('product_services', 'sku')->where(function ($query) use ($creatorId) {
            return $query->where('created_by', $creatorId);
        });

        if ($ignoreId) {
            $unique->ignore($ignoreId);
        }

        return ['required', 'string', 'max:191', $unique];
    }

    private function productSkuMessages(): array
    {
        return [
            'sku.unique' => __('This SKU is already used by another product. Please enter a different SKU or auto-generate one.'),
        ];
    }

    private function nextAvailableSku(int $creatorId): string
    {
        $skus = ProductService::where('created_by', $creatorId)->pluck('sku');
        $maxNumeric = 0;
        $pad = 6;

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '' && preg_match('/^\d+$/', $sku)) {
                $num = (int) ltrim($sku, '0');
                if ($sku === '0' || $sku === '00') {
                    $num = 0;
                }
                if ($num > $maxNumeric) {
                    $maxNumeric = $num;
                }
                $pad = max($pad, strlen($sku));
            }
        }

        $next = $maxNumeric + 1;
        $candidate = str_pad((string) $next, $pad, '0', STR_PAD_LEFT);

        // Guarantee uniqueness even if alphanumeric SKUs collide with the padded number.
        while (ProductService::where('created_by', $creatorId)->where('sku', $candidate)->exists()) {
            $next++;
            $candidate = str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }
}
