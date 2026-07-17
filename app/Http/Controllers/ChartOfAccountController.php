<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountSubType;
use App\Models\ChartOfAccountType;
use App\Models\ChartOfAccountParent;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    public function index(Request $request)
    {
        if (! \Auth::user()->can('manage chart of account')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();
        ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);
        ChartOfAccountHierarchy::syncTypesFromParents($creatorId);

        $start = $request->start_date ?: date('Y-01-01');
        $end = $request->end_date ?: date('Y-m-d', strtotime('+1 day'));
        $search = trim($request->search ?? '');

        $filter = [
            'startDateRange' => $start,
            'endDateRange' => $end,
            'search' => $search,
        ];

        $types = ChartOfAccountType::where('created_by', $creatorId)->orderBy('id')->get();

        $ulane = auth()->user()->lang ?? 'en';
        $name_column = match ($ulane) {
            'fr' => 'name_fr',
            'zh' => 'name_zh',
            'es' => 'name_es',
            'pt' => 'name_pt_br',
            default => 'name',
        };

        $chartAccounts = [];

        foreach ($types as $type) {
            $accounts = ChartOfAccountHierarchy::accountsForCreator($creatorId, (int) $type->id)
                ->load('subType');

            $tree = ChartOfAccountHierarchy::buildTree($accounts, $name_column);

            if ($search !== '') {
                $tree = $tree->filter(function ($root) use ($search, $name_column) {
                    return ChartOfAccountHierarchy::pruneTreeForSearch($root, $search, $name_column);
                })->values();
            }

            if ($tree->isEmpty()) {
                continue;
            }

            // Duplicate type names (re-import) must not overwrite a populated section with an empty one.
            if (! isset($chartAccounts[$type->name]) || $chartAccounts[$type->name]->isEmpty()) {
                $chartAccounts[$type->name] = $tree;
            }
        }

        return view('chartOfAccount.index', compact('chartAccounts', 'types', 'filter', 'name_column'));
    }

    public function create()
    {
        $ulane = auth()->user()->lang ?? 'en';
        $name_column = match ($ulane) {
            'fr' => 'name_fr',
            'zh' => 'name_zh',
            'es' => 'name_es',
            'pt' => 'name_pt_br',
            default => 'name',
        };

        $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->get();

        $account_type = [];

        foreach ($types as $type) {
            $accountTypes = ChartOfAccountSubType::where('type', $type->id)->where('created_by', \Auth::user()->creatorId())->get();
            $temp = [];
            foreach ($accountTypes as $accountType) {
                $temp[$accountType->id] = $accountType->$name_column;
            }
            $account_type[$type->$name_column] = $temp;
        }
        $selectAcc = [
            null => 'Select',
        ];
        $account_type = array_merge($selectAcc, $account_type);

        return view('chartOfAccount.create', compact('account_type'));
    }

    public function store(Request $request)
    {
        if (! \Auth::user()->can('create chart of account')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name_pt_br' => 'required',
                'sub_type' => 'required',
                'code' => [
                    'required',
                    Rule::unique('chart_of_accounts', 'code')
                        ->where(function ($query) {
                            return $query->where('created_by', \Auth::user()->creatorId());
                        }),
                ],
            ]
        );
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $subType = ChartOfAccountSubType::where('id', $request->sub_type)
            ->where('created_by', \Auth::user()->creatorId())
            ->first();

        if (! $subType) {
            return redirect()->back()->with('error', __('Invalid account type.'));
        }

        $parentAccountId = $this->resolveParentAccountIdFromRequest($request);

        $account = new ChartOfAccount();
        $account->name = $request->name ?? '';
        $account->name_fr = $request->name_fr ?? '';
        $account->name_zh = $request->name_zh ?? '';
        $account->name_es = $request->name_es ?? '';
        $account->name_pt_br = $request->name_pt_br;
        $account->code = $request->code;
        $account->description = $request->description;
        $account->description_fr = $request->description_fr ?? '';
        $account->description_zh = $request->description_zh ?? '';
        $account->description_es = $request->description_es ?? '';
        $account->description_pt_br = $request->description_pt_br ?? '';
        $account->is_enabled = isset($request->is_enabled) ? 1 : 0;
        $account->is_default = 0;
        $account->created_by = \Auth::user()->creatorId();

        if ($parentAccountId) {
            $parentAcc = ChartOfAccount::where('id', $parentAccountId)
                ->where('created_by', \Auth::user()->creatorId())
                ->first();

            if (! $parentAcc) {
                return redirect()->back()->with('error', __('Invalid parent account.'));
            }

            $account->type = $parentAcc->type;
            $account->sub_type = $parentAcc->sub_type;
            $account->parent_account_id = $parentAccountId;
            $account->parent = ChartOfAccountHierarchy::legacyParentRowId($parentAccountId);
        } else {
            $account->type = $subType->type;
            $account->sub_type = $request->sub_type;
            $account->parent = 0;
            $account->parent_account_id = null;
        }

        $account->save();

        if (! $parentAccountId) {
            $parentAccount = new ChartOfAccountParent();
            $parentAccount->name = $account->name;
            $parentAccount->name_fr = $account->name_fr;
            $parentAccount->name_zh = $account->name_zh;
            $parentAccount->name_es = $account->name_es;
            $parentAccount->name_pt_br = $account->name_pt_br;
            $parentAccount->sub_type = $request->sub_type;
            $parentAccount->type = $subType->type;
            $parentAccount->account = $account->id;
            $parentAccount->created_by = \Auth::user()->creatorId();
            $parentAccount->save();
        }

        return redirect()->route('chart-of-account.index')->with('success', __('Account successfully created.'));
    }

    public function show(ChartOfAccount $chartOfAccount, Request $request)
    {
        if (! \Auth::user()->can('ledger report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        if (! empty($request->start_date) && ! empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-m-01');
            $end = date('Y-m-t');
        }

        $accounts = ChartOfAccount::select(\DB::raw('CONCAT(code, " - ", name) AS code_name, id'))
            ->where('created_by', \Auth::user()->creatorId())
            ->get()
            ->pluck('code_name', 'id');
        $accounts->prepend('Select Account', '');

        $account = ! empty($request->account)
            ? ChartOfAccount::find($request->account)
            : ChartOfAccount::find($chartOfAccount->id);

        $filter['startDateRange'] = $start;
        $filter['endDateRange'] = $end;

        return view('chartOfAccount.show', compact('filter', 'account', 'accounts'));
    }

    public function edit(ChartOfAccount $chartOfAccount)
    {
        if ($chartOfAccount->is_default) {
            return redirect()->back();
        }

        if ($chartOfAccount->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $parentOptions = ChartOfAccountHierarchy::optionsForType(
            (int) $chartOfAccount->created_by,
            (int) $chartOfAccount->type,
            (int) $chartOfAccount->id
        );

        $currentParentId = ChartOfAccountHierarchy::effectiveParentAccountId($chartOfAccount) ?? 0;

        return view('chartOfAccount.edit', compact('chartOfAccount', 'parentOptions', 'currentParentId'));
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {
        if ($chartOfAccount->is_default) {
            return redirect()->back();
        }

        if (! \Auth::user()->can('edit chart of account')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name_pt_br' => 'required',
                'code' => [
                    'required',
                    Rule::unique('chart_of_accounts', 'code')
                        ->where(function ($query) {
                            return $query->where('created_by', \Auth::user()->creatorId());
                        })
                        ->ignore($chartOfAccount->id),
                ],
            ]
        );
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $parentAccountId = $this->resolveParentAccountIdFromRequest($request);

        if ($parentAccountId && (int) $parentAccountId === (int) $chartOfAccount->id) {
            return redirect()->back()->with('error', __('An account cannot be its own parent.'));
        }

        if ($parentAccountId && in_array(
            (int) $parentAccountId,
            ChartOfAccountHierarchy::descendantIds((int) $chartOfAccount->id),
            true
        )) {
            return redirect()->back()->with('error', __('Invalid parent account.'));
        }

        $chartOfAccount->name = $request->name ?? '';
        $chartOfAccount->name_fr = $request->name_fr ?? '';
        $chartOfAccount->name_zh = $request->name_zh ?? '';
        $chartOfAccount->name_es = $request->name_es ?? '';
        $chartOfAccount->name_pt_br = $request->name_pt_br;
        $chartOfAccount->code = $request->code;
        $chartOfAccount->description = $request->description;
        $chartOfAccount->is_enabled = isset($request->is_enabled) ? 1 : 0;

        if ($parentAccountId) {
            $parentAcc = ChartOfAccount::where('id', $parentAccountId)
                ->where('created_by', \Auth::user()->creatorId())
                ->first();
            if (! $parentAcc) {
                return redirect()->back()->with('error', __('Invalid parent account.'));
            }
            $chartOfAccount->parent_account_id = $parentAccountId;
            $chartOfAccount->parent = ChartOfAccountHierarchy::legacyParentRowId($parentAccountId);
            $chartOfAccount->type = $parentAcc->type;
            $chartOfAccount->sub_type = $parentAcc->sub_type;
        } else {
            if (ChartOfAccount::where('parent_account_id', $chartOfAccount->id)->exists()) {
                return redirect()->back()->with('error', __('Remove or reassign sub-accounts before making this a top-level account.'));
            }
            $chartOfAccount->parent_account_id = null;
            $chartOfAccount->parent = 0;
        }

        $chartOfAccount->save();

        $parentLink = ChartOfAccountParent::where('account', $chartOfAccount->id)->first();
        if ($parentLink) {
            $parentLink->name = $chartOfAccount->name;
            $parentLink->name_fr = $chartOfAccount->name_fr;
            $parentLink->name_zh = $chartOfAccount->name_zh;
            $parentLink->name_es = $chartOfAccount->name_es;
            $parentLink->name_pt_br = $chartOfAccount->name_pt_br;
            $parentLink->save();
        }

        return redirect()->route('chart-of-account.index')->with('success', __('Account successfully updated.'));
    }

    public function destroy(ChartOfAccount $chartOfAccount)
    {
        if (! \Auth::user()->can('delete chart of account')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (ChartOfAccount::where('parent_account_id', $chartOfAccount->id)->exists()) {
            return redirect()->back()->with('error', __('Delete sub-accounts first.'));
        }

        if ((int) $chartOfAccount->parent === 0) {
            $parent = ChartOfAccountParent::where('account', $chartOfAccount->id)->first();
            if ($parent) {
                $parent->delete();
            }
        }

        $chartOfAccount->delete();

        return redirect()->route('chart-of-account.index')->with('success', __('Account successfully deleted.'));
    }

    public function getSubType(Request $request)
    {
        $subTypeId = (int) $request->type;
        if ($subTypeId <= 0) {
            return response()->json(['0' => __('Select an account')]);
        }

        $options = ChartOfAccountHierarchy::optionsForSubType(
            \Auth::user()->creatorId(),
            $subTypeId,
            null,
            'Select an account'
        );

        return response()
            ->json($options)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @deprecated Use getSubType (full tree). Kept for backward compatibility.
     */
    public function getParentChildren(Request $request)
    {
        return $this->getSubType($request);
    }

    private function resolveParentAccountIdFromRequest(Request $request): ?int
    {
        $id = $request->input('parent_account_id');
        if (empty($id) || $id === '0') {
            $id = $request->input('parent');
        }
        if (empty($id) || $id === '0') {
            return null;
        }

        return (int) $id;
    }
}
