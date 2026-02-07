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
use Google\Service\Dataproc\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;




class ProductServiceController extends Controller
{
    public function index(Request $request)
    {

        if (\Auth::user()->can('manage product & service')) {
            $category = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->where('type', '=', 'product & service')->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');

            if (!empty($request->category)) {

                $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())->where('category_id', $request->category)->with(['category', 'unit'])->get();
            } else {
                $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())->with(['category', 'unit'])->get();
            }

            return view('productservice.index', compact('productServices', 'category'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function create()
    {
        if (\Auth::user()->can('create product & service')) {
            $customFields = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'product')->get();
            $category     = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->where('type', '=', 'product & service')->get()->pluck('name', 'id');
            $unit         = ProductServiceUnit::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $tax          = Tax::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $incomeChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name, chart_of_accounts.id as id'))
                ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                ->where('chart_of_account_types.name', 'Receita / Rendimento')
                ->where('parent', '=', 0)
                ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                ->pluck('code_name', 'id');
            $incomeChartAccounts->prepend('Select Account', 0);

            $incomeSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
            $incomeSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $incomeSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
            $incomeSubAccounts->where('chart_of_account_types.name', 'Receita / Rendimento');
            $incomeSubAccounts->where('chart_of_accounts.parent', '!=', 0);
            $incomeSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $incomeSubAccounts = $incomeSubAccounts->get()->toArray();


            $expenseChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name, chart_of_accounts.id as id'))
                ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                ->whereIn('chart_of_account_types.name', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)'])
                ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                ->pluck('code_name', 'id');
            $expenseChartAccounts->prepend('Select Account', '');

            $expenseSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
            $expenseSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $expenseSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
            $expenseSubAccounts->whereIn('chart_of_account_types.name', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)']);
            $expenseSubAccounts->where('chart_of_accounts.parent', '!=', 0);
            $expenseSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $expenseSubAccounts = $expenseSubAccounts->get()->toArray();
            $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();

            return view('productservice.create', compact('category', 'unit', 'tax', 'customFields', 'incomeChartAccounts', 'incomeSubAccounts', 'expenseChartAccounts', 'expenseSubAccounts', 'priceLevels'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {

        if (\Auth::user()->can('create product & service')) {

            $rules = [
                'name' => 'required',
                'sku' => [
                    'required', Rule::unique('product_services')->where(function ($query) {
                        return $query->where('created_by', \Auth::user()->id);
                    })
                ],
                'sale_price' => 'required|numeric',
                'purchase_price' => 'required|numeric',
                'category_id' => 'required',
                'unit_id' => 'required',
                'type' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->route('productservice.index')->with('error', $messages->first());
            }

            $productService                      = new ProductService();
            $productService->name                = $request->name;
            $productService->description         = $request->description;
            $productService->sku                 = $request->sku;
            $productService->sale_price          = $request->sale_price;
            $productService->purchase_price      = $request->purchase_price;
            $productService->tax_id              = !empty($request->tax_id) ? implode(',', $request->tax_id) : '';
            $productService->unit_id             = $request->unit_id;
            $productService->price_level_id             = $request->price_level_id ?? null;
            if (!empty($request->quantity)) {
                $productService->quantity        = $request->quantity;
            } else {
                $productService->quantity   = 0;
            }
            $productService->type                       = $request->type;
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
                $category = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->where('type', '=', 'product & service')->get()->pluck('name', 'id');
                $unit     = ProductServiceUnit::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
                $tax      = Tax::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');

                $productService->customField = CustomField::getData($productService, 'product');
                $customFields                = CustomField::where('created_by', '=', \Auth::user()->creatorId())->where('module', '=', 'product')->get();
                $productService->tax_id      = explode(',', $productService->tax_id);
                $incomeChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name, chart_of_accounts.id as id'))
                    ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                    ->where('chart_of_account_types.name', 'Receita / Rendimento')
                    ->where('parent', '=', 0)
                    ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                    ->pluck('code_name', 'id');
                $incomeChartAccounts->prepend('Select Account', 0);


                $incomeSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
                $incomeSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
                $incomeSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
                $incomeSubAccounts->where('chart_of_account_types.name', 'Receita / Rendimento');
                $incomeSubAccounts->where('chart_of_accounts.parent', '!=', 0);
                $incomeSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                $incomeSubAccounts = $incomeSubAccounts->get()->toArray();


                $expenseChartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name, chart_of_accounts.id as id'))
                    ->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type')
                    ->whereIn('chart_of_account_types.name', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)'])
                    ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
                    ->pluck('code_name', 'id');
                $expenseChartAccounts->prepend('Select Account', '');

                $expenseSubAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.name) AS code_name,chart_of_accounts.id, chart_of_accounts.code, chart_of_account_parents.account'));
                $expenseSubAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
                $expenseSubAccounts->leftjoin('chart_of_account_types', 'chart_of_account_types.id', 'chart_of_accounts.type');
                $expenseSubAccounts->whereIn('chart_of_account_types.name', ['Despesas', 'Custo das Mercadorias Vendidas (CMV)']);
                $expenseSubAccounts->where('chart_of_accounts.parent', '!=', 0);
                $expenseSubAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                $expenseSubAccounts = $expenseSubAccounts->get()->toArray();
                $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();

                return view('productservice.edit', compact('category', 'unit', 'tax', 'productService', 'customFields', 'incomeChartAccounts', 'expenseChartAccounts', 'incomeSubAccounts', 'expenseSubAccounts', 'priceLevels'));
            } else {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function update(Request $request, $id)
    {

        if (\Auth::user()->can('edit product & service')) {
            $productService = ProductService::find($id);
            if ($productService->created_by == \Auth::user()->creatorId()) {
                $rules = [
                    'name' => 'required',
                    'sku' => 'required', Rule::unique('product_services')->ignore($productService->id),
                    'sale_price' => 'required|numeric',
                    'purchase_price' => 'required|numeric',
                    'category_id' => 'required',
                    'unit_id' => 'required',
                    'type' => 'required',

                ];

                $validator = \Validator::make($request->all(), $rules);

                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->route('productservice.index')->with('error', $messages->first());
                }

                $productService->name           = $request->name;
                $productService->description    = $request->description;
                $productService->sku            = $request->sku;
                $productService->sale_price     = $request->sale_price;
                $productService->purchase_price = $request->purchase_price;
                $productService->tax_id         = !empty($request->tax_id) ? implode(',', $request->tax_id) : '';
                $productService->unit_id        = $request->unit_id;
                $productService->price_level_id             = $request->price_level_id ?? null;
                if (!empty($request->quantity)) {
                    $productService->quantity   = $request->quantity;
                } else {
                    $productService->quantity   = 0;
                }
                $productService->type                       = $request->type;
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
                $productService->quantity = (isset($row[$request['type']]) && $row[$request['type']] == 'product') ? $row[$request['quantity']] : 0;
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

            $output = "";
            $isPos = ($lastsegment === 'pos');

            if ($isPos) {
                // POS (retail/restaurant): show all products where type = product, stock = product.quantity
                $q = ProductService::getallproducts()->with(['unit']);
                if ($request->cat_id !== '' && $request->search == '') {
                    if ($request->cat_id != '0') {
                        $q = $q->where('category_id', $request->cat_id);
                    }
                } else {
                    if ($request->search != '') {
                        $q = $q->where('product_services.' . $request->type, 'LIKE', "%{$request->search}%");
                    }
                    if ($request->cat_id != '0') {
                        $q = $q->where('category_id', $request->cat_id);
                    }
                }
                $products = $q->get();
            } elseif ($request->war_id == '0') {
                $ids = WarehouseProduct::where('warehouse_id', 1)->get()->pluck('product_id')->toArray();

                if ($request->cat_id !== '' && $request->search == '') {
                    if ($request->cat_id == '0') {
                        $products = ProductService::getallproducts()->whereIn('product_services.id', $ids)->with(['unit'])->get();
                    } else {
                        $products = ProductService::getallproducts()->where('category_id', $request->cat_id)->whereIn('product_services.id', $ids)->with(['unit'])->get();
                    }
                } else {
                    if ($request->cat_id == '0') {
                        $products = ProductService::getallproducts()->where('product_services.' . $request->type, 'LIKE', "%{$request->search}%")->with(['unit'])->get();
                    } else {
                        $products = ProductService::getallproducts()->where('product_services.' . $request->type, 'LIKE', "%{$request->search}%")->orWhere('category_id', $request->cat_id)->with(['unit'])->get();
                    }
                }
            } else {
                $ids = WarehouseProduct::where('warehouse_id', $request->war_id)->get()->pluck('product_id')->toArray();
                if ($request->cat_id == '0') {
                    $products = ProductService::getallproducts()->whereIn('product_services.id', $ids)->with(['unit'])->get();
                } else {
                    $products = ProductService::getallproducts()->whereIn('product_services.id', $ids)->where('category_id', $request->cat_id)->with(['unit'])->get();
                }
            }

            if (count($products) > 0) {
                foreach ($products as $key => $product) {
                    $quantity = $isPos ? (int) $product->quantity : $product->warehouseProduct($product->id, $request->war_id != 0 ? $request->war_id : 7);

                    $unit = (!empty($product) && !empty($product->unit)) ? $product->unit->name : '';

                        if (!empty($product->pro_image)) {
                            $image_url = ('uploads/pro_image') . '/' . $product->pro_image;
                        } else {
                            $image_url = ('uploads/pro_image') . '/default.png';
                        }
                        if ($request->session_key == 'purchases') {
                            $productprice = $product->purchase_price != 0 ? $product->purchase_price : 0;
                        } else if ($request->session_key == 'pos') {
                            $productprice = $product->sale_price != 0 ? $product->sale_price : 0;
                        } else {
                            $productprice = $product->sale_price != 0 ? $product->sale_price : $product->purchase_price;
                        }
                        $taxRate = \Utility::totalTaxRate($product->tax_id);
                        $printZone = $product->print_zone ?? '';
                        $output .= '

                                    <div class="col-xl-2 col-lg-2 col-md-3 col-sm-4 col-6">
                                        <div class="tab-pane fade show active toacart w-100" data-url="' . url('add-to-cart/' . $product->id . '/' . $lastsegment) . '" data-product-id="' . $product->id . '" data-product-name="' . e($product->name) . '" data-product-price="' . $productprice . '" data-product-tax="' . $taxRate . '" data-product-image="' . e(asset(Storage::url($image_url))) . '" data-product-print-zone="' . e($printZone) . '">
                                            <div class="position-relative card">
                                                <img alt="Image placeholder" src="' . asset(Storage::url($image_url)) . '" class="card-image avatar shadow hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                                  <div class="p-0 custom-card-body card-body d-flex ">
                                                    <div class="card-body mt-2 p-0 text-left card-bottom-content">
                                                        <h5 class="mb-2 text-dark product-title-name">' . $product->name . '</h5>
                                                        <h6 class="mb-2 text-dark product-title-name small">' . $product->sku . '</h6>
                                                        <h5 class="">' . Auth::user()->priceFormat($productprice) . '<small class="badge badge-danger mb-0 rounded">' . $quantity . ' ' . $unit . '</small></h5>
                                                        <button type="button" id="" class="btn btn-primary btn-sm rounded d-flex align-items-center gap-3" ><i class="ti ti-plus"></i> ' . __('Add to Cart') . '</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                            ';

                }

                return Response($output);
            } else {
                $output='<div class="card card-body col-12 text-center">
                    <h5>'.__("No Product Available").'</h5>
                    </div>';
                return Response($output);
            }
        }
    }

    public function addToCart(Request $request, $id, $session_key)
    {

        $canManage = Auth::user()->can('manage product & service') || (Auth::user()->can('manage pos') || Auth::user()->can('pos restaurant waiter') || Auth::user()->can('pos restaurant full') || Auth::user()->can('pos restaurant manager')) && $session_key == 'pos';
        if ($canManage && $request->ajax()) {
            $product = ProductService::find($id);
            $productquantity = 0;

            if ($product) {
                // POS: stock = product's quantity; others (e.g. purchase): stock = purchase-based
                $productquantity = ($session_key == 'pos') ? (int) $product->quantity : $product->getTotalProductQuantity();
            }

            if (!$product || ($session_key == 'pos' && $productquantity == 0)) {
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

            if ($session_key == 'purchases') {

                $productprice = $product->purchase_price != 0 ? $product->purchase_price : 0;
            } else if ($session_key == 'pos') {

                $productprice = $product->sale_price != 0 ? $product->sale_price : 0;
            } else {

                $productprice = $product->sale_price != 0 ? $product->sale_price : $product->purchase_price;
            }

            $originalquantity = (int)$productquantity;

            $taxes = Utility::tax($product->tax_id);

            $totalTaxRate = Utility::totalTaxRate($product->tax_id);

            $product_tax = '';
            $product_tax_id = [];
            foreach ($taxes as $tax) {
                $product_tax .= !empty($tax) ? "<span class='badge bg-primary'>" . $tax->name . ' (' . $tax->rate . '%)' . "</span><br>" : '';
                $product_tax_id[] = !empty($tax) ? $tax->id : 0;
            }

            if (empty($product_tax)) {
                $product_tax = "-";
            }
            $producttax = $totalTaxRate;


            $tax = ($productprice * $producttax) / 100;

            $subtotal        = $productprice + $tax;
            $cart            = session()->get($session_key);
            $image_url = (!empty($product->pro_image) && Storage::exists($product->pro_image)) ? $product->pro_image : 'uploads/pro_image/' . $product->pro_image;

            $model_delete_id = 'delete-form-' . $id;

            $carthtml = '';
            $sku = $product->sku ?? '';
            $catName = $product->category ? $product->category->name : '';
            $taxDisplay = str_replace("'", "&#39;", strip_tags($product_tax));
            $printZone = $product->print_zone ?? '';
            if ($session_key == 'pos') {
                if ($request->get('restaurant')) {
                    $carthtml .= '<tr data-product-id="' . $id . '" id="product-id-' . $id . '" data-name="' . e($productname) . '" data-price="' . $productprice . '" data-quantity="1" data-tax="' . $producttax . '" data-subtotal="' . $subtotal . '" data-print-zone="' . e($printZone) . '">
                            <td class="cart-images"><img alt="" src="' . asset(Storage::url($image_url)) . '" class="card-image avatar shadow hover-shadow-lg"></td>
                            <td class="name">' . e($productname) . '</td>
                            <td><span class="quantity buttons_added"><input type="button" value="-" class="minus"><input type="number" step="1" min="1" name="quantity" class="input-number" data-url="' . url('update-cart/') . '" data-id="' . $id . '" value="1"><input type="button" value="+" class="plus"></span></td>
                            <td class="tax">' . $taxDisplay . '</td>
                            <td class="price">' . Auth::user()->priceFormat($productprice) . '</td>
                            <td class="subtotal">' . Auth::user()->priceFormat($subtotal) . '</td>
                            <td class="action-btn"><a href="#" class="btn btn-sm bg-danger bs-pass-para-pos pos-remove-from-cart" data-confirm="' . e(__('Are You Sure?')) . '" data-text="' . e(__('This action can not be undone. Do you want to continue?')) . '" data-confirm-yes="' . $model_delete_id . '" title="' . __('Delete') . '" data-id="' . $id . '"><i class="ti ti-trash text-white"></i></a><form method="post" action="' . url('remove-from-cart') . '" id="' . $model_delete_id . '"><input name="_method" type="hidden" value="DELETE"><input name="_token" type="hidden" value="' . csrf_token() . '"><input type="hidden" name="session_key" value="' . $session_key . '"><input type="hidden" name="id" value="' . $id . '"></form></td>
                        </tr>';
                } else {
                $carthtml .= '<div class="pos-cart-card-item card border rounded-2 mb-2 shadow-sm" data-product-id="' . $id . '" id="product-id-' . $id . '" data-name="' . e($productname) . '" data-price="' . $productprice . '" data-quantity="1" data-tax="' . $producttax . '" data-subtotal="' . $subtotal . '" data-discount="0" data-netprice="' . $subtotal . '" data-print-zone="' . e($printZone) . '">';
                $carthtml .= '<div class="card-body p-2">';
                $carthtml .= '<div class="d-flex justify-content-between align-items-start gap-2">';
                $carthtml .= '<div class="flex-grow-1 min-w-0"><h6 class="mb-0 text-dark fw-bold text-truncate name">' . e($productname) . '</h6>';
                if ($sku) {
                    $carthtml .= '<small class="text-muted d-block">SKU: ' . e($sku) . '</small>';
                }
                if ($catName) {
                    $carthtml .= '<span class="badge bg-light text-dark border mt-1">' . e($catName) . '</span>';
                }
                $carthtml .= '</div>';
                $carthtml .= '<div class="action-btn"><a href="#" class="btn btn-sm btn-danger p-1 bs-pass-para-pos" data-confirm="' . e(__('Are You Sure?')) . '" data-text="' . e(__('This action can not be undone. Do you want to continue?')) . '" data-confirm-yes="' . $model_delete_id . '" title="' . __('Delete') . '" data-id="' . $id . '"><i class="ti ti-trash"></i></a>';
                $carthtml .= '<form method="post" action="' . url('remove-from-cart') . '" accept-charset="UTF-8" id="' . $model_delete_id . '"><input name="_method" type="hidden" value="DELETE"><input name="_token" type="hidden" value="' . csrf_token() . '"><input type="hidden" name="session_key" value="' . $session_key . '"><input type="hidden" name="id" value="' . $id . '"></form></div></div>';
                $carthtml .= '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">';
                $carthtml .= '<span class="quantity buttons_added d-inline-flex align-items-center border rounded"><input type="button" value="-" class="minus btn btn-sm btn-outline-secondary border-0 py-0"><input type="number" step="1" min="1" max="" name="quantity" title="' . __('Quantity') . '" class="input-number form-control form-control-sm border-0 text-center" style="width: 2.5rem;" data-url="' . url('update-cart/') . '" data-id="' . $id . '" value="1"><input type="button" value="+" class="plus btn btn-sm btn-outline-secondary border-0 py-0"></span>';
                $carthtml .= '<div class="text-end"><div class="fw-bold text-dark subtotal">' . Auth::user()->priceFormat($subtotal) . '</div><small class="text-muted price">' . Auth::user()->priceFormat($productprice) . ' ' . __('each') . '</small></div></div>';
                $carthtml .= '<small class="text-muted d-block mt-1 tax">' . __('Tax') . ': ' . $taxDisplay . '</small>';
                $carthtml .= '</div></div>';
                }
            } else {
                $carthtml .= '<tr data-product-id="' . $id . '" id="product-id-' . $id . '">
                            <td class="cart-images">
                                <img alt="Image placeholder" src="' . asset(Storage::url($image_url)) . '" class="card-image avatar shadow hover-shadow-lg">
                            </td>
                            <td class="name">' . $productname . '</td>
                            <td class="">
                                   <span class="quantity buttons_added">
                                         <input type="button" value="-" class="minus">
                                         <input type="number" step="1" min="1" max="" name="quantity" title="' . __('Quantity') . '" class="input-number" size="4" value="1" data-url="' . url('update-cart/') . '" data-id="' . $id . '">
                                         <input type="button" value="+" class="plus">
                                   </span>
                            </td>
                            <td class="tax">' . $product_tax . '</td>
                            <td class="price">' . Auth::user()->priceFormat($productprice) . '</td>
                            <td class="subtotal">' . Auth::user()->priceFormat($subtotal) . '</td>
                            <td class="action-btn mt-3">
                                 <a href="#" class="btn btn-sm bg-danger bs-pass-para-pos" data-confirm="' . __("Are You Sure?") . '" data-text="' . __("This action can not be undone. Do you want to continue?") . '" data-confirm-yes=' . $model_delete_id . ' title="' . __('Delete') . '" data-id="' . $id . '">
                                   <span class=""><i class="ti ti-trash text-white"></i></span>
                                 </a>
                                 <form method="post" action="' . url('remove-from-cart') . '"  accept-charset="UTF-8" id="' . $model_delete_id . '">
                                      <input name="_method" type="hidden" value="DELETE">
                                      <input name="_token" type="hidden" value="' . csrf_token() . '">
                                      <input type="hidden" name="session_key" value="' . $session_key . '">
                                      <input type="hidden" name="id" value="' . $id . '">
                                 </form>
                            </td>
                        </tr>';
            }

            // if cart is empty then this the first product
            if (!$cart) {
                $cart = [
                    $id => [
                        "name" => $productname,
                        "quantity" => 1,
                        "price" => $productprice,
                        "id" => $id,
                        "tax" => $producttax,
                        "subtotal" => $subtotal,
                        "originalquantity" => $originalquantity,
                        "product_tax" => $product_tax,
                        "product_tax_id" => !empty($product_tax_id) ? implode(',', $product_tax_id) : 0,
                    ],
                ];


                if ($originalquantity < $cart[$id]['quantity'] && $session_key == 'pos') {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }

                session()->put($session_key, $cart);

                return response()->json(
                    [
                        'code' => 200,
                        'status' => 'Success',
                        'success' => $productname . __(' added to cart successfully!'),
                        'product' => $cart[$id],
                        'carthtml' => $carthtml,
                    ]
                );
            }

            // if cart not empty then check if this product exist then increment quantity
            if (isset($cart[$id])) {

                $cart[$id]['quantity']++;
                $cart[$id]['id'] = $id;

                $subtotal = $cart[$id]["price"] * $cart[$id]["quantity"];
                $tax      = ($subtotal * $cart[$id]["tax"]) / 100;

                $cart[$id]["subtotal"]         = $subtotal + $tax;
                $cart[$id]["originalquantity"] = $originalquantity;

                if ($originalquantity < $cart[$id]['quantity'] && $session_key == 'pos') {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }

                session()->put($session_key, $cart);

                return response()->json(
                    [
                        'code' => 200,
                        'status' => 'Success',
                        'success' => $productname . __(' added to cart successfully!'),
                        'product' => $cart[$id],
                        'carttotal' => $cart,
                    ]
                );
            }

            // if item not exist in cart then add to cart with quantity = 1
            $cart[$id] = [
                "name" => $productname,
                "quantity" => 1,
                "price" => $productprice,
                "tax" => $producttax,
                "subtotal" => $subtotal,
                "id" => $id,
                "originalquantity" => $originalquantity,
                "product_tax" => $product_tax,
            ];

            if ($originalquantity < $cart[$id]['quantity'] && $session_key == 'pos') {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            session()->put($session_key, $cart);

            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => $productname . __(' added to cart successfully!'),
                    'product' => $cart[$id],
                    'carthtml' => $carthtml,
                    'carttotal' => $cart,
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

    public function updateCart(Request $request)
    {

        $id          = $request->id;
        $quantity    = $request->quantity;
        $discount    = $request->discount;
        $session_key = $request->session_key;

        $canManage = Auth::user()->can('manage product & service') || ((Auth::user()->can('manage pos') || Auth::user()->can('pos restaurant waiter') || Auth::user()->can('pos restaurant full') || Auth::user()->can('pos restaurant manager')) && $session_key == 'pos');
        if ($canManage && $request->ajax() && isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);


            if (isset($cart[$id]) && $quantity == 0) {
                unset($cart[$id]);
            }

            if ($quantity) {

                $cart[$id]["quantity"] = $quantity;

                $producttax            = isset($cart[$id]) ? $cart[$id]["tax"] : 0;
                $productprice          = $cart[$id]["price"];

                $subtotal = $productprice * $quantity;
                $tax      = ($subtotal * $producttax) / 100;

                $cart[$id]["subtotal"] = $subtotal + $tax;
            }

            // POS: validate against product's quantity (stock)
            if (isset($cart[$id]) && $session_key == 'pos') {
                $product = ProductService::find($id);
                $maxQty = $product ? (int) $product->quantity : 0;
                if ($cart[$id]['quantity'] > $maxQty) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }
                $cart[$id]['originalquantity'] = $maxQty;
            }

            $subtotal = array_sum(array_column($cart, 'subtotal'));
            $discount = $request->discount;
            $total = $subtotal - $discount;

            $totalDiscount = Auth::user()->priceFormat($total);
            $discount = $totalDiscount;


            session()->put($session_key, $cart);

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

        $canManage = Auth::user()->can('manage product & service') || (Auth::user()->can('manage pos') && $session_key == 'pos');
        if ($canManage && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);
            if (isset($cart) && count($cart) > 0) {
                session()->forget($session_key);
            }

            return redirect()->back()->with('error', __('Cart is empty!'));
        } else {
            return redirect()->back()->with('error', __('Cart cannot be empty!.'));
        }
    }

    public function warehouseemptyCart(Request $request)
    {
        $session_key = $request->session_key;

        $cart = session()->get($session_key);
        if (isset($cart) && count($cart) > 0) {
            session()->forget($session_key);
        }

        return response()->json();
    }

    public function removeFromCart(Request $request)
    {
        $id          = $request->id;
        $session_key = $request->session_key;
        $canManage = Auth::user()->can('manage product & service') || (Auth::user()->can('manage pos') && $session_key == 'pos');
        if ($canManage && isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);
            if (isset($cart[$id])) {
                unset($cart[$id]);
                session()->put($session_key, $cart);
            }

            return redirect()->back()->with('error', __('Product removed from cart!'));
        } else {
            return redirect()->back()->with('error', __('This Product is not found!'));
        }
    }
}
