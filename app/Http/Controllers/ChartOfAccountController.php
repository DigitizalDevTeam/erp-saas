<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountSubType;
use App\Models\ChartOfAccountType;
use App\Models\User;
use App\Models\Utility;
use App\Models\JournalItem;
use App\Models\ChartOfAccountParent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{

    public function index(Request $request)
    {
    if (\Auth::user()->can('manage chart of account')) {

        $start = $request->start_date ?: date('Y-01-01');
        $end = $request->end_date ?: date('Y-m-d', strtotime('+1 day'));
        $search = trim($request->search ?? '');

        $filter = [
            'startDateRange' => $start,
            'endDateRange' => $end,
            'search' => $search,
        ];

        $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->get();

        // Name column for locale-aware sibling sort (used in ordering)
        $ulane = auth()->user()->lang ?? 'en';
        $name_column = match ($ulane) {
            'fr' => 'name_fr',
            'zh' => 'name_zh',
            'es' => 'name_es',
            'pt' => 'name_pt_br',
            default => 'name',
        };

        $query = ChartOfAccountParent::where('created_by', \Auth::user()->creatorId())
            ->with([
                'children' => function ($q) use ($search, $name_column) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($name_column);
                    if (!empty($search)) {
                        $q->where('name', 'LIKE', "%{$search}%")
                          ->orWhere('code', 'LIKE', "%{$search}%");
                    }
                },
                'children.subChildren' => function ($q) use ($name_column) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($name_column);
                },
                'children.subType',
                'children.subChildren.subType',
                'children.parentAccount',
                'accountMain'
            ]);

        // ✅ Only include parents that match OR have matching children
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhereHas('accountMain', function ($q2) use ($search) {
                      $q2->where('code', 'LIKE', "%{$search}%")
                         ->orWhere('name', 'LIKE', "%{$search}%");
                  })
                  ->orWhereHas('children', function ($q3) use ($search) {
                      $q3->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('code', 'LIKE', "%{$search}%");
                  });
            });
        }

        $parents = $query->get()->sortBy(function ($parent) use ($name_column) {
            // Primary: account code (numeric), secondary: name (siblings alphabetically)
            return [(int) ($parent->accountMain->code ?? 0), $parent->{$name_column} ?? ''];
        });


        // ✅ Hide parents with no matching children and not matching themselves
        if (!empty($search)) {
            $parents = $parents->filter(function ($parent) use ($search) {
                $matchesParent = stripos($parent->name, $search) !== false;
                $matchesCode = $parent->accountMain && stripos($parent->accountMain->code, $search) !== false;
                return $matchesParent || $matchesCode || $parent->children->isNotEmpty();
            });
        }

        $parents = $parents->groupBy('type');

        $chartAccounts = [];
        foreach ($types as $type) {
            $chartAccounts[$type->name] = $parents[$type->id] ?? [];
        }

        return view('chartOfAccount.index', compact('chartAccounts', 'types', 'filter', 'name_column'));
    }

    return redirect()->back()->with('error', __('Permission denied.'));
}


    public function create()
    {
        $ulane = auth()->user()->lang ?? 'en';
        $name_column = 'name';
        switch($ulane)
        {
            case 'en':
                $name_column = 'name';
                break;
            case 'fr':
                $name_column = 'name_fr';
                break;
            case 'zh':
                $name_column = 'name_zh';
                break;
            case 'es':
                $name_column = 'name_es';
                break;
            case 'pt':
                $name_column = 'name_pt_br';
                break;
            default:
                $name_column = 'name';
                break;
        }
        $types = ChartOfAccountType::where('created_by',\Auth::user()->creatorId())->get();

        $account_type = [];

        foreach ($types as $type) {
            $accountTypes = ChartOfAccountSubType::where('type', $type->id)->where('created_by',\Auth::user()->creatorId())->get();
            $temp = [];
            foreach($accountTypes as $accountType)
            {
                $temp[$accountType->id] = $accountType->$name_column;
            }
            $account_type[$type->$name_column] = $temp;
        }
        $selectAcc =     [
            null => "Select",
            ];
               $account_type =  array_merge($selectAcc, $account_type);

        return view('chartOfAccount.create', compact('account_type'));
    }


    public function store(Request $request)
    {
        if(\Auth::user()->can('create chart of account'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'name_pt_br' => 'required',
                                   'sub_type' => 'required',
                                   'code'        => [
                                        'required',
                                        Rule::unique('chart_of_accounts', 'code')
                                            ->where(function ($query) {
                                                return $query->where('created_by', \Auth::user()->creatorId());
                                            }),
                                    ],
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $type = ChartOfAccountSubType::where('id',$request->sub_type)->where('created_by', '=', \Auth::user()->creatorId())->first();
            $parentId = $request->input('parent');
            if (empty($parentId) || $parentId == '0')
            {
                $account              = new ChartOfAccount();
                $account->name        = $request->name ?? '';
                $account->name_fr        = $request->name_fr ?? '';
                $account->name_zh        = $request->name_zh ?? '';
                $account->name_es        = $request->name_es ?? '';
                $account->name_pt_br        = $request->name_pt_br;
                $account->code        = $request->code;
                $account->type        = $type->type;
                $account->sub_type    = $request->sub_type;
                $account->parent      = 0;
                $account->description = $request->description;
                $account->description_fr = $request->description_fr;
                $account->description_zh = $request->description_zh;
                $account->description_es = $request->description_es;
                $account->description_pt_br = $request->description_pt_br;
                $account->is_enabled  = isset($request->is_enabled) ? 1 : 0;
                $account->is_default  = 0;
                $account->created_by  = \Auth::user()->creatorId();
                $account->save();
                
                $parentAccount              = new ChartOfAccountParent();
                $parentAccount->name        = $account->name;
                $parentAccount->name_fr        = $account->name_fr;
                $parentAccount->name_zh        = $account->name_zh;
                $parentAccount->name_es        = $account->name_es;
                $parentAccount->name_pt_br        = $account->name_pt_br;
                $parentAccount->sub_type    = $request->sub_type;
                $parentAccount->type        = $type->type;
                $parentAccount->account      = $account->id;
                $parentAccount->created_by  = \Auth::user()->creatorId();
                $parentAccount->save();
                
                
            }
            else{
                // Resolve parent: request can be ChartOfAccount id (main account) or we resolve via ChartOfAccount.parent
                $existingparentAccount = ChartOfAccountParent::where('account', $parentId)
                    ->where('created_by', \Auth::user()->creatorId())
                    ->first();
                if (!$existingparentAccount) {
                    $selectedAccount = ChartOfAccount::where('id', $parentId)
                        ->where('created_by', \Auth::user()->creatorId())
                        ->first();
                    if ($selectedAccount && $selectedAccount->parent > 0) {
                        $existingparentAccount = ChartOfAccountParent::where('id', $selectedAccount->parent)
                            ->where('created_by', \Auth::user()->creatorId())
                            ->first();
                    }
                }
                // if($account != null){
    
                //     if ($existingparentAccount) {
                //         $parentAccount = $existingparentAccount;
                //     } else {
                //         $parentAccount              = new ChartOfAccountParent();
                //     }
    
                //     $parentAccount->name        = $account->name;
                //     $parentAccount->sub_type    = $request->sub_type;
                //     $parentAccount->type        = $type->type;
                //     $parentAccount->account      = $request->parent;
                //     $parentAccount->created_by  = \Auth::user()->creatorId();
                //     $parentAccount->save();
                // }
    
                $account              = new ChartOfAccount();
                $account->name        = $request->name ?? '';
                $account->name_fr        = $request->name_fr ?? '';
                $account->name_zh        = $request->name_zh ?? '';
                $account->name_es        = $request->name_es ?? '';
                $account->name_pt_br        = $request->name_pt_br ?? '';
                $account->code        = $request->code;
                $account->type        = $type->type;
                $account->sub_type    = $request->sub_type;
                $account->parent      = !empty($existingparentAccount) ? $existingparentAccount->id : 0;
                $parentAccountIdInput = $request->input('parent_account_id');
                if (!empty($parentAccountIdInput) && $parentAccountIdInput != '0') {
                    $underSub = ChartOfAccount::where('id', $parentAccountIdInput)
                        ->where('parent', $existingparentAccount->id)
                        ->where('created_by', \Auth::user()->creatorId())
                        ->first();
                    $account->parent_account_id = $underSub ? $underSub->id : null;
                } else {
                    $account->parent_account_id = null;
                }
                $account->description = $request->description;
                $account->is_enabled  = isset($request->is_enabled) ? 1 : 0;
                $account->created_by  = \Auth::user()->creatorId();
                $account->is_default  = 0;
                $account->save();
            }
            
            
            

            return redirect()->route('chart-of-account.index')->with('success', __('Account successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function show(ChartOfAccount $chartOfAccount,Request $request)
    {
        if(\Auth::user()->can('ledger report'))
        {
            if(!empty($request->start_date) && !empty($request->end_date))
            {
                $start = $request->start_date;
                $end   = $request->end_date;
            }
            else
            {
                $start = date('Y-m-01');
                $end   = date('Y-m-t');
            }
            if(!empty($request->start_date) && !empty($request->end_date))
            {
                $accounts = ChartOfAccount::select(\DB::raw('CONCAT(code, " - ", name) AS code_name, id'))
                    ->where('created_by', \Auth::user()->creatorId())
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<=', $end)
                    ->get()->pluck('code_name', 'id');
                $accounts->prepend('Select Account', '');

            }
            else
            {
                $accounts = ChartOfAccount::select(\DB::raw('CONCAT(code, " - ", name) AS code_name, id'))
                    ->where('created_by', \Auth::user()->creatorId())->get()
                    ->pluck('code_name', 'id');
                $accounts->prepend('Select Account', '');
            }
            if(!empty($request->account))
            {
                $account = ChartOfAccount::find($request->account);
            }
            else
            {
                $account = ChartOfAccount::find($chartOfAccount->id);
            }

            // $journalItems = JournalItem::select('journal_entries.journal_id', 'journal_entries.date as transaction_date', 'journal_items.*')
            //     ->leftjoin('journal_entries', 'journal_entries.id', 'journal_items.journal')
            //     ->where('journal_entries.created_by', '=', \Auth::user()->creatorId())
            //     ->where('account', !empty($account) ? $account->id : 0);
            // $journalItems->where('date', '>=', $start);
            // $journalItems->where('date', '<=', $end);
            // $journalItems = $journalItems->get();

            $balance = 0;
            $debit   = 0;
            $credit  = 0;

            // foreach($journalItems as $item)
            // {
            //     if($item->debit > 0)
            //     {
            //         $debit += $item->debit;
            //     }

            //     else
            //     {
            //         $credit += $item->credit;
            //     }

            //     $balance = $credit - $debit;
            // }

            $filter['startDateRange'] = $start;
            $filter['endDateRange']   = $end;

            return view('chartOfAccount.show', compact('filter', 'account', 'accounts'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function edit(ChartOfAccount $chartOfAccount)
    {
        if($chartOfAccount->is_default)
        {
            return redirect()->back();
        }
        $types = ChartOfAccountType::get()->pluck('name', 'id');
        $types->prepend('Select Account Type', 0);

        return view('chartOfAccount.edit', compact('chartOfAccount', 'types'));
    }


    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {

        if($chartOfAccount->is_default)
        {
            return redirect()->back();
        }
        if(\Auth::user()->can('edit chart of account'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'name_pt_br' => 'required',
                                   'code'        => [
                                        'required',
                                        Rule::unique('chart_of_accounts', 'code')
                                            ->where(function ($query) {
                                                return $query->where('created_by', \Auth::user()->creatorId());
                                            })
                                            ->ignore($chartOfAccount->id),
                                    ],
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }


            $chartOfAccount->name        = $request->name ?? '';
            $chartOfAccount->name_fr        = $request->name_fr ?? '';
            $chartOfAccount->name_zh        = $request->name_zh ?? '';
            $chartOfAccount->name_es        = $request->name_es ?? '';
            $chartOfAccount->name_pt_br        = $request->name_pt_br;
            $chartOfAccount->code        = $request->code;
            $chartOfAccount->description = $request->description;
            $chartOfAccount->is_enabled  = isset($request->is_enabled) ? 1 : 0;
            $chartOfAccount->save();



            return redirect()->route('chart-of-account.index')->with('success', __('Account successfully updated.'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }


    public function destroy(ChartOfAccount $chartOfAccount)
    {
        if(\Auth::user()->can('delete chart of account'))
        {
            if($chartOfAccount->parent == 0)
            {
                $parent = ChartOfAccountParent::where('account' , $chartOfAccount->id)->first();
                if($parent)
                {
                    $parent->delete();
                }
            }
            $chartOfAccount->delete();

            return redirect()->route('chart-of-account.index')->with('success', __('Account successfully deleted.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getSubType(Request $request)
    {
        $ulane = auth()->user()->lang ?? 'en';
        $name_column = 'name';
        switch($ulane)
        {
            case 'en':
                $name_column = 'name';
                break;
            case 'fr':
                $name_column = 'name_fr';
                break;
            case 'zh':
                $name_column = 'name_zh';
                break;
            case 'es':
                $name_column = 'name_es';
                break;
            case 'pt':
                $name_column = 'name_pt_br';
                break;
            default:
                $name_column = 'name';
                break;
        }
        // Only parent (top-level) accounts can be selected as parent — they have a row in ChartOfAccountParent
        $parentAccountIds = ChartOfAccountParent::where('created_by', \Auth::user()->creatorId())
            ->pluck('account');
        $types = ChartOfAccount::where('sub_type', $request->type)
            ->whereIn('id', $parentAccountIds)
            ->select('id', "$name_column as name")
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->get()
            ->pluck('name', 'id');
        $types->prepend(__('Select an account'), '0');

        return response()->json($types);
    }

    /**
     * Return direct sub-accounts of a parent (for "Under sub-account" dropdown when creating).
     */
    public function getParentChildren(Request $request)
    {
        $parentAccountId = $request->input('parent');
        if (empty($parentAccountId) || $parentAccountId == '0') {
            return response()->json(['0' => __('Direct under parent')]);
        }
        $parentRecord = ChartOfAccountParent::where('account', $parentAccountId)
            ->where('created_by', \Auth::user()->creatorId())
            ->first();
        if (!$parentRecord) {
            return response()->json(['0' => __('Direct under parent')]);
        }
        $ulane = auth()->user()->lang ?? 'en';
        $name_column = match ($ulane) {
            'fr' => 'name_fr',
            'zh' => 'name_zh',
            'es' => 'name_es',
            'pt' => 'name_pt_br',
            default => 'name',
        };
        $children = ChartOfAccount::where('parent', $parentRecord->id)
            ->whereNull('parent_account_id')
            ->where('created_by', \Auth::user()->creatorId())
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->orderBy($name_column)
            ->get()
            ->pluck($name_column, 'id');
        $children->prepend(__('Direct under parent'), '0');
        return response()->json($children);
    }
}