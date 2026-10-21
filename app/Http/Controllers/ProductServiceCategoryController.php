<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use Illuminate\Http\Request;

class ProductServiceCategoryController extends Controller
{
    public function index()
    {
        if (\Auth::user()->can('manage constant category')) {
            $raw = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->get();
            $byId = $raw->keyBy('id');
            $categories = $raw->sortBy(function ($c) use ($byId) {
                return $c->pathLabel($byId);
            })->values();

            return view('productServiceCategory.index', compact('categories', 'byId'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create()
    {
        if (\Auth::user()->can('create constant category')) {
            $types = ProductServiceCategory::$catTypes;
            $type = ['' => 'Select Category Type'];

            $types = array_merge($type, $types);

            $chart_accounts = ChartOfAccount::where('created_by', \Auth::user()->creatorId())->get()
                ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
            $chart_accounts->prepend('Select Account', '');

            $parentOptions = $this->parentOptionsForUser((int) \Auth::user()->creatorId());

            return view('productServiceCategory.create', compact('types', 'chart_accounts', 'parentOptions'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {

        if (\Auth::user()->can('create constant category')) {

            $validator = \Validator::make(
                $request->all(), [
                    'name' => 'required|max:200',
                    'type' => 'required',
                    'color' => 'required',
                    'parent_id' => 'nullable|integer',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $parentErr = $this->validateCategoryParent($request, null);
            if ($parentErr !== null) {
                return redirect()->back()->with('error', $parentErr);
            }

            $category = new ProductServiceCategory();
            $category->name = $request->name;
            $category->color = $request->color;
            $category->type = $request->type;
            $category->parent_id = $request->filled('parent_id') ? (int) $request->parent_id : null;
            $category->print_zone = $request->type === 'product & service' ? ($request->print_zone ?: null) : null;
            $category->chart_account_id = !empty($request->chart_account) ? $request->chart_account : 0;
            $category->created_by = \Auth::user()->creatorId();
            $category->save();

            return redirect()->route('product-category.index')->with('success', __('Category successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function edit($id)
    {

        if (\Auth::user()->can('edit constant category')) {
            $types = ProductServiceCategory::$catTypes;
            $category = ProductServiceCategory::find($id);

            $parentOptions = $this->parentOptionsForUser((int) \Auth::user()->creatorId(), (int) $category->id);

            return view('productServiceCategory.edit', compact('category', 'types', 'parentOptions'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function update(Request $request, $id)
    {

        if (\Auth::user()->can('edit constant category')) {
            $category = ProductServiceCategory::find($id);
            if ($category->created_by == \Auth::user()->creatorId()) {
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required|max:200',
                        'type' => 'required',
                        'color' => 'required',
                        'parent_id' => 'nullable|integer',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }

                $parentErr = $this->validateCategoryParent($request, (int) $id);
                if ($parentErr !== null) {
                    return redirect()->back()->with('error', $parentErr);
                }

                $category->name = $request->name;
                $category->color = $request->color;
                $category->type = $request->type;
                $category->parent_id = $request->filled('parent_id') ? (int) $request->parent_id : null;
                $category->print_zone = $request->type === 'product & service' ? ($request->print_zone ?: null) : null;
                $category->chart_account_id = !empty($request->chart_account) ? $request->chart_account : 0;
                $category->save();

                return redirect()->route('product-category.index')->with('success', __('Category successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy($id)
    {
        if (\Auth::user()->can('delete constant category')) {
            $category = ProductServiceCategory::find($id);
            if ($category->created_by == \Auth::user()->creatorId()) {

                if (ProductServiceCategory::where('created_by', $category->created_by)->where('parent_id', $category->id)->exists()) {
                    return redirect()->back()->with('error', __('Please delete or reassign subcategories first.'));
                }

                if ($category->type == 0) {
                    $categories = ProductService::where('category_id', $category->id)->first();
                } elseif ($category->type == 1) {
                    $categories = Invoice::where('category_id', $category->id)->first();
                } else {
                    $categories = Bill::where('category_id', $category->id)->first();
                }

                if (!empty($categories)) {
                    return redirect()->back()->with('error', __('this category is already assign so please move or remove this category related data.'));
                }

                $category->delete();

                return redirect()->route('product-category.index')->with('success', __('Category successfully deleted.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getProductCategories()
    {
        $cat = ProductServiceCategory::getallCategories();
        $all_products = ProductService::getallproducts()->count();

        $html = '<div class="mb-0">
                  <div class="card rounded-10 card-stats mb-0 cat-active overflow-hidden" data-id="0">
                     <div class="category-select" data-cat-id="0">
                        <button type="button" class="btn tab-btns btn-primary">' . __("All Categories") . '</button>
                     </div>
                  </div>
               </div>';
        foreach ($cat as $key => $c) {
            $dcls = 'category-select';
            $html .= ' <div class="mb-0 cat-list-btn">
                          <div class="card rounded-10 card-stats mb-0 overflow-hidden " data-id="' . $c->id . '">
                             <div class="' . $dcls . '" data-cat-id="' . $c->id . '">
                                <button type="button" class="btn tab-btns btn-primary">' . $c->name . '</button>
                             </div>
                          </div>
                       </div>';
        }
        return Response($html);
    }

    /**
     * Return product categories as JSON for POS dropdowns (supports parent / subcategory).
     */
    public function getProductCategoriesJson()
    {
        $creatorId = \Auth::user()->creatorId();
        $rows = ProductServiceCategory::query()
            ->where('created_by', $creatorId)
            ->where('type', 'product & service')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $byParent = [];
        foreach ($rows as $c) {
            $pid = $c->parent_id ? (int) $c->parent_id : 0;
            if (! isset($byParent[$pid])) {
                $byParent[$pid] = [];
            }
            $byParent[$pid][] = [
                'id' => (int) $c->id,
                'name' => $c->name,
                'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
            ];
        }

        $categories = [];
        foreach ($rows as $c) {
            $id = (int) $c->id;
            $categories[] = [
                'id' => $id,
                'name' => $c->name,
                'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
                'has_children' => ! empty($byParent[$id]),
            ];
        }

        return response()->json([
            'categories' => $categories,
            'children_by_parent' => $byParent,
        ]);
    }

    public function getAccount(Request $request)
    {

        $chart_accounts = [];
        if ($request->type == 'income') {
            $chart_accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh')
            ->leftjoin('chart_of_account_types', 'chart_of_account_types.id','chart_of_accounts.type')
            ->where('chart_of_account_types.name' ,'Income')
            ->where('parent', '=', 0)
            ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
        } elseif ($request->type == 'expense') {
            $chart_accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh')
            ->leftjoin('chart_of_account_types', 'chart_of_account_types.id','chart_of_accounts.type')
            ->where('chart_of_account_types.name' ,'Expenses')
            ->where('parent', '=', 0)
            ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
        } elseif ($request->type == 'asset') {
            $chart_accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh')
            ->leftjoin('chart_of_account_types', 'chart_of_account_types.id','chart_of_accounts.type')
            ->where('chart_of_account_types.name' ,'Assets')
            ->where('parent', '=', 0)
            ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
        } elseif ($request->type == 'liability') {
            $chart_accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh')
            ->leftjoin('chart_of_account_types', 'chart_of_account_types.id','chart_of_accounts.type')
            ->where('chart_of_account_types.name' ,'Liabilities')
            ->where('parent', '=', 0)
            ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
        } elseif ($request->type == 'equity') {
            $chart_accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh')
            ->leftjoin('chart_of_account_types', 'chart_of_account_types.id','chart_of_accounts.type')
            ->where('chart_of_account_types.name' ,'Equity')
            ->where('parent', '=', 0)
            ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
        } elseif ($request->type == 'costs of good sold') {
            $chart_accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh')
            ->leftjoin('chart_of_account_types', 'chart_of_account_types.id','chart_of_accounts.type')
            ->where('chart_of_account_types.name' ,'Costs of Goods Sold')
            ->where('parent', '=', 0)
            ->where('chart_of_accounts.created_by', \Auth::user()->creatorId())->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->codeNameForLocale()]);
        } else {
            $chart_accounts = 0;
        }

        $subAccounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.name_pt_br', 'chart_of_accounts.name_fr', 'chart_of_accounts.name_es', 'chart_of_accounts.name_zh' , 'chart_of_account_parents.account');
        $subAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
        $subAccounts->where('chart_of_accounts.parent', '!=', 0);
        $subAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
        $subAccounts = $subAccounts->get()->map(fn ($account) => [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->labelForLocale(),
            'account' => $account->account,
        ])->toArray();

    $response = [
        'chart_accounts' => $chart_accounts,
        'sub_accounts' => $subAccounts,
    ];

        return response()->json($response);

    }

    /**
     * Dropdown options: id => "Parent → Child → …" for same-tenant categories.
     */
    protected function parentOptionsForUser(int $creatorId, ?int $excludeCategoryId = null)
    {
        $allFull = ProductServiceCategory::where('created_by', $creatorId)->orderBy('type')->orderBy('name')->get();
        $byId = $allFull->keyBy('id');
        $candidates = $allFull;
        if ($excludeCategoryId !== null) {
            $blocked = array_merge([(int) $excludeCategoryId], ProductServiceCategory::descendantIds((int) $excludeCategoryId, $allFull));
            $candidates = $allFull->filter(function ($c) use ($blocked) {
                return ! in_array((int) $c->id, $blocked, true);
            });
        }

        return $candidates->mapWithKeys(function ($c) use ($byId) {
            return [$c->id => $c->pathLabel($byId)];
        });
    }

    protected function validateCategoryParent(Request $request, ?int $excludeCategoryId = null): ?string
    {
        if (! $request->filled('parent_id')) {
            return null;
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $parent = ProductServiceCategory::where('created_by', $creatorId)->find((int) $request->parent_id);
        if (! $parent) {
            return __('Invalid parent category.');
        }

        if ($parent->type !== $request->type) {
            return __('The parent must be the same category type as this record (e.g. Product & Service with Product & Service).');
        }

        if ($excludeCategoryId !== null) {
            $all = ProductServiceCategory::where('created_by', $creatorId)->get();
            $blocked = array_merge([(int) $excludeCategoryId], ProductServiceCategory::descendantIds((int) $excludeCategoryId, $all));
            if (in_array((int) $request->parent_id, $blocked, true)) {
                return __('You cannot set the parent to this category or one of its subcategories.');
            }
        }

        return null;
    }

}
