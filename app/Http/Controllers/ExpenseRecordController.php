<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\TransactionLines;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseRecordController extends Controller
{
    public const REF_EXPENSE_LINE = 'Expense Record';

    public const REF_PAYMENT_LINE = 'Expense Record Payment';

    public function index()
    {
        if (\Auth::user()->can('manage expense')) {
            $expenses = Expense::where('created_by', \Auth::user()->creatorId())
                ->with(['expenseCategory', 'branch', 'paymentBankAccount', 'chartAccount'])
                ->orderBy('date', 'desc')
                ->get();

            return view('expenses.index', compact('expenses'));
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function create()
    {
        if (\Auth::user()->can('create expense')) {
            $categories = ExpenseCategory::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id')->prepend(__('Select Branch'), 0);
            $bankAccounts = $this->bankAccountsForSelect();
            $chartAccounts = $this->expenseChartAccountsHierarchicalSelect();

            return view('expenses.create', compact('categories', 'branches', 'bankAccounts', 'chartAccounts'));
        }

        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('create expense')) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'date' => 'required|date',
                'amount' => 'required|numeric|min:0',
                'expense_category_id' => [
                    'required',
                    Rule::exists('expense_categories', 'id')->where(function ($q) {
                        $q->where('created_by', \Auth::user()->creatorId());
                    }),
                ],
                'branch_id' => 'nullable|exists:branches,id',
                'payment_account_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('bank_accounts', 'id')->where(function ($q) {
                        $q->where('created_by', \Auth::user()->creatorId());
                    }),
                ],
                'chart_account_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('chart_of_accounts', 'id')->where(function ($q) {
                        $q->where('created_by', \Auth::user()->creatorId());
                    }),
                ],
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $expense = new Expense();
            $expense->name = $request->name;
            $expense->date = $request->date;
            $expense->description = $request->description;
            $expense->amount = $request->amount;
            $expense->expense_category_id = $request->expense_category_id;
            $expense->branch_id = (int) ($request->branch_id ?? 0);
            $expense->payment_account_id = (int) ($request->payment_account_id ?? 0);
            $expense->chart_account_id = (int) ($request->chart_account_id ?? 0);
            $expense->status = Expense::STATUS_PENDING;
            $expense->user_id = \Auth::user()->id;
            $expense->created_by = \Auth::user()->creatorId();

            if ($request->hasFile('attachment')) {
                $filenameWithExt = $request->file('attachment')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('attachment')->getClientOriginalExtension();
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;
                $dir = 'uploads/expenses/';
                $path = Utility::upload_file($request, 'attachment', $fileNameToStore, $dir, []);
                if ($path['flag'] == 1) {
                    $expense->attachment = $fileNameToStore;
                } else {
                    return redirect()->back()->with('error', __($path['msg']));
                }
            }

            $expense->save();

            return redirect()->route('expenses.index')->with('success', __('Expense successfully created.'));
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function show(Expense $expense)
    {
        return redirect()->route('expenses.index');
    }

    public function edit(Expense $expense)
    {
        if (\Auth::user()->can('edit expense')) {
            if ($expense->created_by != \Auth::user()->creatorId()) {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
            $categories = ExpenseCategory::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id')->prepend(__('Select Branch'), 0);
            $bankAccounts = $this->bankAccountsForSelect();
            $chartAccounts = $this->expenseChartAccountsHierarchicalSelect();

            return view('expenses.edit', compact('expense', 'categories', 'branches', 'bankAccounts', 'chartAccounts'));
        }

        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, Expense $expense)
    {
        if (\Auth::user()->can('edit expense')) {
            if ($expense->created_by != \Auth::user()->creatorId()) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }

            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'date' => 'required|date',
                'amount' => 'required|numeric|min:0',
                'expense_category_id' => [
                    'required',
                    Rule::exists('expense_categories', 'id')->where(function ($q) {
                        $q->where('created_by', \Auth::user()->creatorId());
                    }),
                ],
                'branch_id' => 'nullable|exists:branches,id',
                'payment_account_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('bank_accounts', 'id')->where(function ($q) {
                        $q->where('created_by', \Auth::user()->creatorId());
                    }),
                ],
                'chart_account_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('chart_of_accounts', 'id')->where(function ($q) {
                        $q->where('created_by', \Auth::user()->creatorId());
                    }),
                ],
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            if ($expense->status === Expense::STATUS_APPROVED) {
                $err = $this->accountingPrereqErrorMessage($request);
                if ($err !== null) {
                    return redirect()->back()->with('error', $err);
                }
                if ((float) $request->amount <= 0) {
                    return redirect()->back()->with('error', __('Amount must be greater than zero for an approved expense.'));
                }
            }

            $wasApproved = $expense->status === Expense::STATUS_APPROVED;
            $prevAmount = (float) $expense->amount;
            $prevPaymentAccountId = (int) $expense->payment_account_id;

            $newAttachment = null;
            if ($request->hasFile('attachment')) {
                $filenameWithExt = $request->file('attachment')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('attachment')->getClientOriginalExtension();
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;
                $dir = 'uploads/expenses/';
                $path = Utility::upload_file($request, 'attachment', $fileNameToStore, $dir, []);
                if ($path['flag'] != 1) {
                    return redirect()->back()->with('error', __($path['msg']));
                }
                $newAttachment = $fileNameToStore;
            }

            try {
                DB::transaction(function () use ($request, $expense, $wasApproved, $prevAmount, $prevPaymentAccountId, $newAttachment) {
                    $expense->name = $request->name;
                    $expense->date = $request->date;
                    $expense->description = $request->description;
                    $expense->amount = $request->amount;
                    $expense->expense_category_id = $request->expense_category_id;
                    $expense->branch_id = (int) ($request->branch_id ?? 0);
                    $expense->payment_account_id = (int) ($request->payment_account_id ?? 0);
                    $expense->chart_account_id = (int) ($request->chart_account_id ?? 0);
                    if ($newAttachment !== null) {
                        $expense->attachment = $newAttachment;
                    }
                    $expense->save();

                    if ($wasApproved) {
                        $this->reverseApprovedExpenseAccounting($expense->id, $prevAmount, $prevPaymentAccountId);
                        $this->postApprovedExpenseAccounting($expense->fresh());
                    }
                });
            } catch (\Throwable $e) {
                return redirect()->back()->with('error', __('Could not save expense or update accounting. Please check accounts and try again.'));
            }

            return redirect()->route('expenses.index')->with('success', __('Expense successfully updated.'));
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function approve(Expense $expense)
    {
        if (! \Auth::user()->can('approve expense')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->status != Expense::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only pending expenses can be approved.'));
        }

        $err = $this->accountingPrereqErrorMessageForExpense($expense);
        if ($err !== null) {
            return redirect()->back()->with('error', $err);
        }

        if ((float) $expense->amount <= 0) {
            return redirect()->back()->with('error', __('Amount must be greater than zero to post to accounting.'));
        }

        try {
            DB::transaction(function () use ($expense) {
                $this->postApprovedExpenseAccounting($expense);
                $expense->status = Expense::STATUS_APPROVED;
                $expense->save();
            });
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Could not post expense to accounting. Check expense (GL) account and payment account.'));
        }

        return redirect()->route('expenses.index')->with('success', __('Expense approved and posted to accounting.'));
    }

    public function reject(Expense $expense)
    {
        if (! \Auth::user()->can('reject expense')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->status != Expense::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only pending expenses can be rejected.'));
        }
        $expense->status = Expense::STATUS_REJECTED;
        $expense->save();

        return redirect()->route('expenses.index')->with('success', __('Expense rejected successfully.'));
    }

    public function destroy(Expense $expense)
    {
        if (\Auth::user()->can('delete expense')) {
            if ($expense->created_by == \Auth::user()->creatorId()) {
                $attachmentName = $expense->attachment;
                try {
                    DB::transaction(function () use ($expense) {
                        if ($expense->status === Expense::STATUS_APPROVED) {
                            $this->reverseApprovedExpenseAccounting(
                                $expense->id,
                                (float) $expense->amount,
                                (int) $expense->payment_account_id
                            );
                        }
                        $expense->delete();
                    });
                } catch (\Throwable $e) {
                    return redirect()->back()->with('error', __('Could not delete expense or remove accounting entries.'));
                }

                if (! empty($attachmentName)) {
                    $dir = 'uploads/expenses/';
                    $filePath = $dir . $attachmentName;
                    if (\File::exists($filePath)) {
                        \File::delete($filePath);
                    }
                }

                return redirect()->route('expenses.index')->with('success', __('Expense successfully deleted.'));
            }
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    protected function bankAccountsForSelect()
    {
        return BankAccount::select('*', DB::raw("CONCAT(bank_name,' ',holder_name) AS name"))
            ->where('created_by', \Auth::user()->creatorId())
            ->get()
            ->pluck('name', 'id')
            ->prepend(__('Select payment account'), '');
    }

    /**
     * Validates request payload for an expense that must remain accounting-ready (approved).
     */
    protected function accountingPrereqErrorMessage(Request $request): ?string
    {
        $category = ExpenseCategory::find($request->expense_category_id);
        if (! $category || (int) $category->created_by !== (int) \Auth::user()->creatorId()) {
            return __('Invalid category.');
        }
        if (empty($request->chart_account_id)) {
            return __('Select an expense account (GL) required for approved expenses.');
        }
        $coa = ChartOfAccount::find((int) $request->chart_account_id);
        if (! $coa || (int) $coa->created_by !== (int) \Auth::user()->creatorId()) {
            return __('Invalid expense account (GL).');
        }
        $bankId = (int) ($request->payment_account_id ?? 0);
        if ($bankId <= 0) {
            return __('Payment account is required for approved expenses.');
        }
        $bank = BankAccount::find($bankId);
        if (! $bank || (int) $bank->created_by !== (int) \Auth::user()->creatorId() || empty($bank->chart_account_id)) {
            return __('Invalid payment account.');
        }

        return null;
    }

    protected function accountingPrereqErrorMessageForExpense(Expense $expense): ?string
    {
        $category = ExpenseCategory::find($expense->expense_category_id);
        if (! $category || (int) $category->created_by !== (int) \Auth::user()->creatorId()) {
            return __('Invalid category.');
        }
        if (empty($expense->chart_account_id)) {
            return __('Edit the expense and select an expense account (GL) before approving.');
        }
        $coa = ChartOfAccount::find((int) $expense->chart_account_id);
        if (! $coa || (int) $coa->created_by !== (int) \Auth::user()->creatorId()) {
            return __('Invalid expense account (GL).');
        }
        if (empty($expense->payment_account_id)) {
            return __('Edit the expense and select a payment account before approving.');
        }
        $bank = BankAccount::find($expense->payment_account_id);
        if (! $bank || (int) $bank->created_by !== (int) \Auth::user()->creatorId() || empty($bank->chart_account_id)) {
            return __('Invalid payment account. Edit the expense and choose a valid bank account.');
        }

        return null;
    }

    protected function postApprovedExpenseAccounting(Expense $expense): void
    {
        $chartAccountId = $expense->chart_account_id ? (int) $expense->chart_account_id : 0;
        $bankAccount = BankAccount::find($expense->payment_account_id);
        if ($chartAccountId <= 0 || ! $bankAccount || empty($bankAccount->chart_account_id)) {
            throw new \RuntimeException('Missing accounting accounts');
        }

        $date = $expense->date ? $expense->date->format('Y-m-d') : date('Y-m-d');
        $amount = (float) $expense->amount;

        Utility::addTransactionLines([
            'account_id' => $chartAccountId,
            'transaction_type' => 'Debit',
            'transaction_amount' => $amount,
            'reference' => self::REF_EXPENSE_LINE,
            'reference_id' => $expense->id,
            'reference_sub_id' => 1,
            'date' => $date,
        ], 'create');

        Utility::addTransactionLines([
            'account_id' => $bankAccount->chart_account_id,
            'transaction_type' => 'Debit',
            'transaction_amount' => $amount,
            'reference' => self::REF_PAYMENT_LINE,
            'reference_id' => $expense->id,
            'reference_sub_id' => 1,
            'date' => $date,
        ], 'create');

        Utility::bankAccountBalance($bankAccount->id, $amount, 'debit');
    }

    /**
     * Expense-type chart of accounts as id => label, with visual hierarchy (parent → sub-account → sub-sub-account).
     */
    protected function expenseChartAccountsHierarchicalSelect(): \Illuminate\Support\Collection
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $ulane = auth()->user()->lang ?? 'en';
        $name_column = match ($ulane) {
            'fr' => 'name_fr',
            'zh' => 'name_zh',
            'es' => 'name_es',
            'pt_br', 'pt-br' => 'name_pt_br',
            default => 'name',
        };

        $expenseType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Expenses')
                    ->orWhere('name_pt_br', 'Despesas');
            })
            ->first();

        $options = collect(['' => __('Select expense account (GL)')]);
        if (! $expenseType) {
            return $options;
        }

        $seen = [];

        $add = function (int $id, string $label) use (&$options, &$seen) {
            if ($id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $options[$id] = $label;
        };

        \App\Support\ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);
        $accounts = \App\Support\ChartOfAccountHierarchy::accountsForCreator($creatorId, (int) $expenseType->id);
        $tree = \App\Support\ChartOfAccountHierarchy::buildTree($accounts, $name_column);

        \App\Support\ChartOfAccountHierarchy::walkTree($tree, function ($node, $depth) use ($add, $name_column) {
            $add((int) $node->id, $this->chartAccountSelectLabel($node, $depth, $name_column));
        });

        return $options;
    }

    protected function chartAccountSelectLabel(ChartOfAccount $account, int $depth, string $name_column): string
    {
        $prefix = $depth > 0 ? str_repeat('· ', $depth) : '';
        $name = $account->{$name_column} ?? $account->name;

        return $prefix . $account->code . ' — ' . $name;
    }

    protected function reverseApprovedExpenseAccounting(int $expenseId, float $amount, int $paymentBankAccountId): void
    {
        TransactionLines::where('reference_id', $expenseId)
            ->whereIn('reference', [self::REF_EXPENSE_LINE, self::REF_PAYMENT_LINE])
            ->delete();

        if ($paymentBankAccountId > 0 && $amount > 0) {
            Utility::bankAccountBalance($paymentBankAccountId, $amount, 'credit');
        }
    }
}
