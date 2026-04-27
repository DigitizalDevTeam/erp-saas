<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Pos;
use App\Models\PosArea;
use App\Models\PosAuditLog;
use App\Models\PosCashIn;
use App\Models\PosCashDrop;
use App\Models\PosPayment;
use App\Services\PosAuditLogService;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\PosTableAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Expense;
use App\Models\BankAccount;
use App\Models\Utility;
use App\Mail\InternalOperationalAlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PosShiftController extends Controller
{
    /**
     * Open a shift from POS (current user). Used when user has no open shift and clicks "Open Shift" on POS.
     */
    public function openFromPos(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $validator = \Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->getMessageBag()->first()], 422);
        }
        $creatorId = \Auth::user()->creatorId();
        $userId = session('pos_pin_user_id', \Auth::user()->id);
        $branchId = $request->branch_id;
        // Ensure branch belongs to company
        $branch = Branch::where('id', $branchId)->where('created_by', $creatorId)->where('is_active', true)->first();
        if (!$branch) {
            return response()->json(['error' => __('Invalid branch.')], 422);
        }
        $actingUser = \App\Models\User::find($userId);
        if ($actingUser && ! $actingUser->canAccessBranchForPos((int) $branchId, $branch->isRestaurant() ? 'restaurant' : 'retail')) {
            return response()->json(['error' => __('You can only open a shift for an allowed branch.')], 422);
        }
        $today = date('Y-m-d');
        $dayClosed = \App\Models\PosDayClosed::where('closed_date', $today)->where('created_by', $creatorId)->first();
        if ($dayClosed) {
            $isOwner = (\Auth::user()->creatorId() === \Auth::user()->id);
            if (!$isOwner) {
                return response()->json(['error' => __('Day is closed. Only an administrator can reopen.')], 422);
            }
        }
        $existing = PosShift::where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('status', PosShift::STATUS_OPEN)
            ->first();
        if ($existing) {
            return response()->json(['error' => __('You already have an open shift for this branch.')], 422);
        }
        $shift = new PosShift();
        $shift->branch_id = $branchId;
        $shift->user_id = $userId;
        $shift->opened_at = now();
        $shift->opening_balance = (float) ($request->opening_balance ?? 0);
        $shift->status = PosShift::STATUS_OPEN;
        $shift->created_by = $creatorId;
        $shift->save();
        return response()->json(['success' => true, 'message' => __('Shift opened successfully.'), 'shift_id' => $shift->id]);
    }

    public function index()
    {
        if (\Auth::user()->canManagePos()) {
            $shifts = PosShift::where('created_by', \Auth::user()->creatorId())
                ->with(['branch', 'user'])
                ->orderBy('opened_at', 'desc')
                ->paginate(20);
            return view('pos_shift.index', compact('shifts'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function create()
    {
        if (\Auth::user()->canManagePos()) {
            $branches = Branch::where('created_by', \Auth::user()->creatorId())
                ->where('is_active', true)
                ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
                ->get()
                ->pluck('name', 'id');
            $users = User::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            return view('pos_shift.create', compact('branches', 'users'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $validator = \Validator::make($request->all(), [
                'branch_id' => 'required|exists:branches,id',
                'user_id' => 'required|exists:users,id',
                'opening_balance' => 'nullable|numeric|min:0',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $assignee = \App\Models\User::find($request->user_id);
            $shiftBranch = Branch::where('id', $request->branch_id)->where('created_by', \Auth::user()->creatorId())->first();
            if ($assignee && $shiftBranch && ! $assignee->canAccessBranchForPos((int) $request->branch_id, $shiftBranch->isRestaurant() ? 'restaurant' : 'retail')) {
                return redirect()->back()->with('error', __('This user cannot open a shift for this branch.'));
            }
            $openShift = PosShift::where('branch_id', $request->branch_id)
                ->where('user_id', $request->user_id)
                ->where('status', PosShift::STATUS_OPEN)
                ->first();
            if ($openShift) {
                return redirect()->back()->with('error', __('This user already has an open shift for this branch.'));
            }
            $shift = new PosShift();
            $shift->branch_id = $request->branch_id;
            $shift->user_id = $request->user_id;
            $shift->opened_at = now();
            $shift->opening_balance = (float) ($request->opening_balance ?? 0);
            $shift->status = PosShift::STATUS_OPEN;
            $shift->created_by = \Auth::user()->creatorId();
            $shift->save();
            return redirect()->route('pos-shift.index')->with('success', __('Shift opened successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function show(PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId()) {
        $pos_shift->load(['branch', 'user', 'tableAssignments.table', 'tableAssignments.user', 'cashDrops.user', 'cashIns.user']);
            return view('pos_shift.show', compact('pos_shift'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /**
     * Phase 2: Store cash drop (withdrawal) for open shift.
     */
    public function storeCashDrop(Request $request, PosShift $pos_shift)
    {
        if (!\Auth::user()->canManagePos() || $pos_shift->created_by != \Auth::user()->creatorId() || $pos_shift->status !== PosShift::STATUS_OPEN) {
            if ($request->wantsJson()) {
                return response()->json(['error' => __('Permission denied.')], 403);
            }
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);
        $drop = new PosCashDrop();
        $drop->shift_id = $pos_shift->id;
        $drop->amount = (float) $request->amount;
        $drop->reason = $request->reason ? trim($request->reason) : null;
        $drop->user_id = \Auth::user()->id;
        $drop->created_by = \Auth::user()->creatorId();
        $drop->save();
        PosAuditLogService::log(PosAuditLog::ACTION_CASH_DROP, 'pos_cash_drop', (int) $drop->id, __('Cash drop'), ['shift_id' => $pos_shift->id, 'amount' => $drop->amount, 'reason' => $drop->reason]);
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => __('Cash drop recorded.'), 'drop' => $drop->load('user')]);
        }
        return redirect()->route('pos-shift.show', $pos_shift)->with('success', __('Cash drop recorded.'));
    }

    /**
     * Cash In (top-up) during an open shift.
     * Optionally posts accounting transfer between bank accounts if accounts provided.
     */
    public function storeCashIn(Request $request, PosShift $pos_shift)
    {
        if (!\Auth::user()->canManagePos() || $pos_shift->created_by != \Auth::user()->creatorId() || $pos_shift->status !== PosShift::STATUS_OPEN) {
            if ($request->wantsJson()) {
                return response()->json(['error' => __('Permission denied.')], 403);
            }
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
            'source_account_id' => 'nullable|integer|min:0',
            'target_account_id' => 'nullable|integer|min:0',
        ]);

        $creatorId = \Auth::user()->creatorId();
        $amount = (float) $request->amount;
        $sourceId = (int) ($request->source_account_id ?? 0);
        $targetId = (int) ($request->target_account_id ?? 0);

        try {
            DB::transaction(function () use ($pos_shift, $creatorId, $amount, $sourceId, $targetId, $request) {
                $cashIn = new PosCashIn();
                $cashIn->shift_id = $pos_shift->id;
                $cashIn->amount = $amount;
                $cashIn->reason = $request->reason ? trim($request->reason) : null;
                $cashIn->source_account_id = $sourceId;
                $cashIn->target_account_id = $targetId;
                $cashIn->user_id = \Auth::id();
                $cashIn->created_by = $creatorId;
                $cashIn->save();

                // If both accounts are set and valid, post as internal transfer (Debit target, Credit source).
                if ($sourceId > 0 && $targetId > 0 && $sourceId !== $targetId) {
                    $source = BankAccount::where('id', $sourceId)->where('created_by', $creatorId)->first();
                    $target = BankAccount::where('id', $targetId)->where('created_by', $creatorId)->first();
                    if ($source && $target && !empty($source->chart_account_id) && !empty($target->chart_account_id)) {
                        $date = date('Y-m-d');
                        Utility::addTransactionLines([
                            'account_id' => (int) $target->chart_account_id,
                            'transaction_type' => 'Debit',
                            'transaction_amount' => $amount,
                            'reference' => 'POS Cash In',
                            'reference_id' => $cashIn->id,
                            'reference_sub_id' => 1,
                            'date' => $date,
                        ], 'create');
                        Utility::addTransactionLines([
                            'account_id' => (int) $source->chart_account_id,
                            'transaction_type' => 'Credit',
                            'transaction_amount' => $amount,
                            'reference' => 'POS Cash In',
                            'reference_id' => $cashIn->id,
                            'reference_sub_id' => 2,
                            'date' => $date,
                        ], 'create');

                        // Update bank account balances (system convention).
                        Utility::bankAccountBalance($target->id, $amount, 'credit');
                        Utility::bankAccountBalance($source->id, $amount, 'debit');
                    }
                }

                PosAuditLogService::log(PosAuditLog::ACTION_CASH_IN, 'pos_cash_in', (int) $cashIn->id, __('Cash in'), [
                    'shift_id' => $pos_shift->id,
                    'amount' => $amount,
                    'source_account_id' => $sourceId,
                    'target_account_id' => $targetId,
                    'reason' => $cashIn->reason,
                ]);
            });
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => __('Could not record cash in.')], 500);
            }
            return redirect()->back()->with('error', __('Could not record cash in.'));
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => __('Cash in recorded.')]);
        }
        return redirect()->route('pos-shift.show', $pos_shift)->with('success', __('Cash in recorded.'));
    }

    /**
     * Cash Out: create an approved expense paid from a cash/bank account, linked to the shift.
     * Requires expense approval permission (authorization at the moment of payout).
     */
    public function storeCashOutExpense(Request $request, PosShift $pos_shift)
    {
        if (!\Auth::user()->can('approve expense') || !$pos_shift || $pos_shift->created_by != \Auth::user()->creatorId() || $pos_shift->status !== PosShift::STATUS_OPEN) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'chart_account_id' => 'required|exists:chart_of_accounts,id',
            'payment_account_id' => 'required|exists:bank_accounts,id',
            'description' => 'nullable|string|max:1000',
        ]);

        $creatorId = \Auth::user()->creatorId();
        $bank = BankAccount::where('id', (int) $request->payment_account_id)->where('created_by', $creatorId)->first();
        if (!$bank || empty($bank->chart_account_id)) {
            return redirect()->back()->with('error', __('Invalid payment account.'));
        }

        try {
            DB::transaction(function () use ($request, $pos_shift, $creatorId) {
                $expense = new Expense();
                $expense->name = $request->name;
                $expense->date = date('Y-m-d');
                $expense->description = $request->description ? trim($request->description) : null;
                $expense->amount = (float) $request->amount;
                $expense->expense_category_id = (int) $request->expense_category_id;
                $expense->branch_id = (int) ($pos_shift->branch_id ?? 0);
                $expense->pos_shift_id = (int) $pos_shift->id;
                $expense->payment_account_id = (int) $request->payment_account_id;
                $expense->chart_account_id = (int) $request->chart_account_id;
                $expense->status = Expense::STATUS_APPROVED;
                $expense->user_id = \Auth::id();
                $expense->created_by = $creatorId;
                $expense->save();

                // Post accounting using the same conventions as ExpenseRecordController.
                $date = date('Y-m-d');
                $amount = (float) $expense->amount;
                Utility::addTransactionLines([
                    'account_id' => (int) $expense->chart_account_id,
                    'transaction_type' => 'Debit',
                    'transaction_amount' => $amount,
                    'reference' => 'Expense Record',
                    'reference_id' => $expense->id,
                    'reference_sub_id' => 1,
                    'date' => $date,
                ], 'create');
                Utility::addTransactionLines([
                    'account_id' => (int) $bank->chart_account_id,
                    'transaction_type' => 'Debit',
                    'transaction_amount' => $amount,
                    'reference' => 'Expense Record Payment',
                    'reference_id' => $expense->id,
                    'reference_sub_id' => 1,
                    'date' => $date,
                ], 'create');
                Utility::bankAccountBalance($bank->id, $amount, 'debit');

                PosAuditLogService::log(PosAuditLog::ACTION_CASH_OUT_EXPENSE, 'expense', (int) $expense->id, __('Cash out (expense)'), [
                    'shift_id' => $pos_shift->id,
                    'amount' => $amount,
                    'payment_account_id' => (int) $expense->payment_account_id,
                    'chart_account_id' => (int) $expense->chart_account_id,
                    'expense_category_id' => (int) $expense->expense_category_id,
                ]);
            });
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Could not record cash out expense.'));
        }

        return redirect()->route('pos-shift.show', $pos_shift)->with('success', __('Cash out expense recorded.'));
    }

    /**
     * Proper shift report: sales list, payment summary, cash drops, opening/closing balance. Printable.
     */
    public function shiftReport(PosShift $pos_shift)
    {
        if (!\Auth::user()->canManagePos() || $pos_shift->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $pos_shift->load(['branch', 'user', 'cashDrops.user', 'cashIns.user']);
        $posIds = \App\Models\Pos::where('shift_id', $pos_shift->id)->pluck('id');
        $sales = \App\Models\Pos::where('shift_id', $pos_shift->id)->with(['items', 'posPayments'])->orderBy('pos_id')->get();
        $paymentsByMethod = \App\Models\PosPayment::whereIn('pos_id', $posIds)->selectRaw('payment_method, sum(amount) as total, count(*) as cnt')->groupBy('payment_method')->get()->keyBy('payment_method');
        $cashTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', \App\Models\PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $balanceCash = (float) \App\Models\InvoicePayment::where('pos_shift_id', $pos_shift->id)->where('payment_method', 0)->sum('amount');
        $cashTotal += $balanceCash;
        $cardTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = (float) \App\Models\PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
        $salesTotal = $sales->sum(fn ($p) => $p->getTotal());
        $cashDropsTotal = (float) PosCashDrop::where('shift_id', $pos_shift->id)->sum('amount');
        $cashInsTotal = (float) PosCashIn::where('shift_id', $pos_shift->id)->sum('amount');
        $cashOutExpensesTotal = (float) Expense::where('pos_shift_id', $pos_shift->id)->where('created_by', \Auth::user()->creatorId())->where('status', Expense::STATUS_APPROVED)->sum('amount');
        $expectedCash = (float) ($pos_shift->opening_balance ?? 0) + $cashTotal + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
        $voidedLines = \App\Models\PosProduct::whereIn('pos_id', $posIds)
            ->whereNotNull('voided_at')
            ->with(['product', 'pos', 'voidedByUser'])
            ->orderBy('voided_at')
            ->get();
        $productQtySummary = \App\Models\PosProduct::selectRaw('product_id, SUM(quantity) as qty_total, SUM(price * quantity) as amount_total')
            ->whereIn('pos_id', $posIds)
            ->whereNull('voided_at')
            ->groupBy('product_id')
            ->with('product')
            ->orderByRaw('SUM(quantity) DESC')
            ->get();

        return view('pos_shift.report', compact('pos_shift', 'sales', 'paymentsByMethod', 'cashTotal', 'cardTotal', 'creditTotal', 'salesTotal', 'cashDropsTotal', 'cashInsTotal', 'cashOutExpensesTotal', 'voidedLines', 'balanceCash', 'expectedCash', 'productQtySummary'));
    }

    public function closeForm(PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $pos_shift->load(['cashDrops', 'cashIns']);
            return view('pos_shift.close', compact('pos_shift'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function close(Request $request, PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $request->validate([
                'closing_balance' => 'required|numeric|min:0',
                'tips' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string|max:5000',
            ], [
                'closing_balance.min' => __('Closing balance must be zero or positive (physical cash counted in the drawer).'),
            ]);

            $posIds = Pos::where('shift_id', $pos_shift->id)->pluck('id');
            $salesTotal = PosPayment::whereIn('pos_id', $posIds)->sum('amount');
            $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
                $q->where('payment_method', \App\Models\PosPayment::METHOD_CASH)->orWhereNull('payment_method');
            })->sum('amount');
            $balanceCash = (float) \App\Models\InvoicePayment::where('pos_shift_id', $pos_shift->id)->where('payment_method', 0)->sum('amount');
            $cashTotal += $balanceCash;
            $cardTotal = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', [\App\Models\PosPayment::METHOD_CARD, \App\Models\PosPayment::METHOD_MPESA, \App\Models\PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'])->sum('amount');
            $creditTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
            $cashDropsTotal = (float) PosCashDrop::where('shift_id', $pos_shift->id)->sum('amount');
            $cashInsTotal = (float) PosCashIn::where('shift_id', $pos_shift->id)->sum('amount');
            $cashOutExpensesTotal = (float) Expense::where('pos_shift_id', $pos_shift->id)->where('created_by', \Auth::user()->creatorId())->where('status', Expense::STATUS_APPROVED)->sum('amount');
            $closingBalance = (float) $request->closing_balance;
            $expectedCash = $pos_shift->opening_balance + $cashTotal + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
            $difference = $closingBalance - $expectedCash;

            $pos_shift->closed_at = now();
            $pos_shift->closing_balance = $closingBalance;
            $pos_shift->cash_total = $cashTotal;
            $pos_shift->card_total = $cardTotal;
            $pos_shift->credit_total = $creditTotal;
            $pos_shift->difference = $difference;
            $pos_shift->tips = (float) ($request->tips ?? 0);
            $pos_shift->notes = $request->notes;
            $pos_shift->sales_count = $posIds->count();
            $pos_shift->sales_total = $salesTotal;
            $pos_shift->status = PosShift::STATUS_CLOSED;
            $pos_shift->save();
            PosAuditLogService::log(PosAuditLog::ACTION_CLOSE_SHIFT, 'pos_shift', (int) $pos_shift->id, __('Shift closed'), ['branch_id' => $pos_shift->branch_id, 'closing_balance' => $closingBalance, 'difference' => $difference]);
            $this->releaseShiftTables($pos_shift);
            $this->sendShiftClosedEmailToManagers($pos_shift, (float) $expectedCash);
            return redirect()->route('pos-shift.index')->with('success', __('Shift closed successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /**
     * Close all open shifts for the current user's company and redirect to EOD report.
     * Used when user goes to Day Closed / EOD Report from POS; all open shifts are auto-closed with expected cash as closing balance.
     */
    public function closeAllShiftsAndRedirectToEod(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $date = $request->get('date', date('Y-m-d'));

        $openShifts = PosShift::where('created_by', $creatorId)
            ->where('status', PosShift::STATUS_OPEN)
            ->get();

        foreach ($openShifts as $pos_shift) {
            $posIds = Pos::where('shift_id', $pos_shift->id)->pluck('id');
            $salesTotal = PosPayment::whereIn('pos_id', $posIds)->sum('amount');
            $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
                $q->where('payment_method', \App\Models\PosPayment::METHOD_CASH)->orWhereNull('payment_method');
            })->sum('amount');
            $balanceCash = (float) \App\Models\InvoicePayment::where('pos_shift_id', $pos_shift->id)->where('payment_method', 0)->sum('amount');
            $cashTotal += $balanceCash;
            $cardTotal = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', [\App\Models\PosPayment::METHOD_CARD, \App\Models\PosPayment::METHOD_MPESA, \App\Models\PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'])->sum('amount');
            $creditTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
            $cashDropsTotal = (float) PosCashDrop::where('shift_id', $pos_shift->id)->sum('amount');
            $openingBalance = (float) ($pos_shift->opening_balance ?? 0);
            $expectedCash = $openingBalance + $cashTotal - $cashDropsTotal;
            $closingBalance = $expectedCash;
            $difference = 0;

            $pos_shift->closed_at = now();
            $pos_shift->closing_balance = $closingBalance;
            $pos_shift->cash_total = $cashTotal;
            $pos_shift->card_total = $cardTotal;
            $pos_shift->credit_total = $creditTotal;
            $pos_shift->difference = $difference;
            $pos_shift->tips = 0;
            $pos_shift->notes = __('Auto-closed for EOD report');
            $pos_shift->sales_count = $posIds->count();
            $pos_shift->sales_total = $salesTotal;
            $pos_shift->status = PosShift::STATUS_CLOSED;
            $pos_shift->save();
            PosAuditLogService::log(PosAuditLog::ACTION_CLOSE_SHIFT, 'pos_shift', (int) $pos_shift->id, __('Shift closed (EOD)'), ['branch_id' => $pos_shift->branch_id, 'closing_balance' => $closingBalance, 'difference' => $difference]);
            $this->releaseShiftTables($pos_shift);
            $this->sendShiftClosedEmailToManagers($pos_shift, (float) $expectedCash);
        }

        return redirect()->route('report.pos.eod', ['date' => $date])->with('success', __('All open shifts have been closed.'));
    }

    public function assignTableForm(PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $pos_shift->load('branch');
            $tables = PosTable::where('branch_id', $pos_shift->branch_id)->where('created_by', \Auth::user()->creatorId())->with('area')->orderBy('area_id')->orderBy('name')->get();
            $assigned = PosTableAssignment::where('shift_id', $pos_shift->id)->whereNull('unassigned_at')->pluck('table_id')->toArray();
            return view('pos_shift.assign_table', compact('pos_shift', 'tables', 'assigned'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function assignTable(Request $request, PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $tableIds = $request->table_ids ?? [];
            PosTableAssignment::where('shift_id', $pos_shift->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
            foreach ((array) $tableIds as $tableId) {
                $assignment = new PosTableAssignment();
                $assignment->shift_id = $pos_shift->id;
                $assignment->table_id = $tableId;
                $assignment->user_id = $pos_shift->user_id;
                $assignment->assigned_at = now();
                $assignment->created_by = \Auth::user()->creatorId();
                $assignment->save();
            }
            return redirect()->route('pos-shift.show', $pos_shift)->with('success', __('Tables assigned successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    /**
     * Transfer tables from one shift (waiter) to another. Only Cashier/Manager or pos restaurant full.
     */
    public function transferTables(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        if (\Auth::user()->can('pos restaurant waiter') && !\Auth::user()->can('pos restaurant full') && !\Auth::user()->can('pos restaurant manager')) {
            return response()->json(['error' => __('Only Cashier or Manager can transfer tables.')], 403);
        }
        $validator = \Validator::make($request->all(), [
            'from_shift_id' => 'required|exists:pos_shifts,id',
            'to_shift_id'   => 'required|exists:pos_shifts,id',
            'table_ids'     => 'required|array',
            'table_ids.*'   => 'exists:pos_tables,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->getMessageBag()->first()], 422);
        }
        $fromShift = PosShift::where('id', $request->from_shift_id)->where('created_by', \Auth::user()->creatorId())->first();
        $toShift   = PosShift::where('id', $request->to_shift_id)->where('created_by', \Auth::user()->creatorId())->first();
        if (!$fromShift || !$toShift || $fromShift->branch_id != $toShift->branch_id) {
            return response()->json(['error' => __('Invalid shift or branch mismatch.')], 422);
        }
        if ($fromShift->status !== PosShift::STATUS_OPEN || $toShift->status !== PosShift::STATUS_OPEN) {
            return response()->json(['error' => __('Both shifts must be open.')], 422);
        }
        $tableIds = array_unique((array) $request->table_ids);
        foreach ($tableIds as $tableId) {
            PosTableAssignment::where('shift_id', $fromShift->id)->where('table_id', $tableId)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
            $exists = PosTableAssignment::where('shift_id', $toShift->id)->where('table_id', $tableId)->whereNull('unassigned_at')->exists();
            if (!$exists) {
                $a = new PosTableAssignment();
                $a->shift_id = $toShift->id;
                $a->table_id = $tableId;
                $a->user_id = $toShift->user_id;
                $a->assigned_at = now();
                $a->created_by = \Auth::user()->creatorId();
                $a->save();
            }
        }
        return response()->json(['success' => true, 'message' => __('Tables transferred successfully.')]);
    }

    /**
     * On shift close, unassign tables and force them to available to avoid "occupied but empty" state.
     */
    private function releaseShiftTables(PosShift $shift): void
    {
        $tableIds = PosTableAssignment::where('shift_id', $shift->id)
            ->whereNull('unassigned_at')
            ->pluck('table_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        PosTableAssignment::where('shift_id', $shift->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => now()]);

        if (! empty($tableIds)) {
            PosTable::whereIn('id', $tableIds)
                ->where('created_by', (int) $shift->created_by)
                ->update([
                    'status' => PosTable::STATUS_AVAILABLE,
                    'current_guests' => null,
                    'customer_name' => null,
                ]);
        }
    }

    /**
     * Notify manager-level users when a shift is closed.
     */
    private function sendShiftClosedEmailToManagers(PosShift $shift, float $expectedCash): void
    {
        try {
            $creatorId = (int) ($shift->created_by ?: \Auth::user()->creatorId());

            $settings = Utility::settingsById($creatorId);
            $fallback = Utility::settings();
            $driver = (string) ($settings['mail_driver'] ?: ($fallback['mail_driver'] ?? 'smtp'));
            $host = (string) ($settings['mail_host'] ?: ($fallback['mail_host'] ?? ''));
            $port = (string) ($settings['mail_port'] ?: ($fallback['mail_port'] ?? ''));
            $encryption = (string) ($settings['mail_encryption'] ?: ($fallback['mail_encryption'] ?? ''));
            $username = (string) ($settings['mail_username'] ?: ($fallback['mail_username'] ?? ''));
            $password = (string) ($settings['mail_password'] ?: ($fallback['mail_password'] ?? ''));
            $fromAddress = (string) ($settings['mail_from_address'] ?: ($fallback['mail_from_address'] ?? ''));
            $fromName = (string) ($settings['mail_from_name'] ?: ($fallback['mail_from_name'] ?? ''));
            config([
                // Keep compatibility with existing code paths.
                'mail.driver' => $driver,
                'mail.host' => $host,
                'mail.port' => $port,
                'mail.encryption' => $encryption,
                'mail.username' => $username,
                'mail.password' => $password,
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $fromName,
                // Ensure Laravel mailer resolution has a concrete default mailer.
                'mail.default' => $driver !== '' ? $driver : (config('mail.default') ?: 'smtp'),
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $port !== '' ? (int) $port : 587,
                'mail.mailers.smtp.encryption' => $encryption !== '' ? $encryption : null,
                'mail.mailers.smtp.username' => $username,
                'mail.mailers.smtp.password' => $password,
                'mail.from.address' => $fromAddress !== '' ? $fromAddress : (config('mail.from.address') ?: 'no-reply@example.com'),
                'mail.from.name' => $fromName !== '' ? $fromName : (config('mail.from.name') ?: config('app.name')),
            ]);

            $shift->loadMissing(['branch', 'user']);

            $reportUrl = route('pos-shift.report', $shift);
            $openedAt = $shift->opened_at ? $shift->opened_at->format('Y-m-d H:i') : '-';
            $closedAt = $shift->closed_at ? $shift->closed_at->format('Y-m-d H:i') : now()->format('Y-m-d H:i');
            $notes = trim((string) ($shift->notes ?? ''));

            $subject = 'POS Shift Closed - #' . $shift->id . ' - ' . ($shift->branch->name ?? 'Branch');
            $body = implode("\n", [
                'A POS shift has been closed.',
                '',
                'Shift ID: #' . $shift->id,
                'Branch: ' . ($shift->branch->name ?? '-'),
                'User: ' . ($shift->user->name ?? '-'),
                'Opened At: ' . $openedAt,
                'Closed At: ' . $closedAt,
                '',
                'Opening Balance: ' . number_format((float) ($shift->opening_balance ?? 0), 2),
                'Expected Cash: ' . number_format((float) $expectedCash, 2),
                'Closing Balance: ' . number_format((float) ($shift->closing_balance ?? 0), 2),
                'Difference: ' . number_format((float) ($shift->difference ?? 0), 2),
                'Cash Total: ' . number_format((float) ($shift->cash_total ?? 0), 2),
                'Card Total: ' . number_format((float) ($shift->card_total ?? 0), 2),
                'Credit Total: ' . number_format((float) ($shift->credit_total ?? 0), 2),
                'Sales Count: ' . (int) ($shift->sales_count ?? 0),
                'Sales Total: ' . number_format((float) ($shift->sales_total ?? 0), 2),
                'Tips: ' . number_format((float) ($shift->tips ?? 0), 2),
                'Notes: ' . ($notes !== '' ? $notes : '-'),
                '',
                'Shift report: ' . $reportUrl,
            ]);

            $recipients = User::where('created_by', $creatorId)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get()
                ->filter(function (User $u) {
                    return $u->can('pos restaurant manager') || $u->can('manage pos');
                })
                ->pluck('email')
                ->unique()
                ->values();

            foreach ($recipients as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                try {
                    Mail::to($email)->send(new InternalOperationalAlertMail($subject, $body));
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }
}
