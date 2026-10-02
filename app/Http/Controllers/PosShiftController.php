<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Pos;
use App\Models\PosArea;
use App\Models\PosAuditLog;
use App\Models\PosCashIn;
use App\Models\PosCashDrop;
use App\Models\PosPayment;
use App\Services\PosActorService;
use App\Services\BusinessDayLockService;
use App\Services\PosAuditLogService;
use App\Support\AttendanceShiftCatalog;
use App\Services\PosShiftReportService;
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
use App\Support\LoggedMail;
use App\Support\PosEodReportAlerts;

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
            'operational_shift' => 'required|in:morning,afternoon,night',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->getMessageBag()->first()], 422);
        }
        $openingBalance = $this->resolveOpeningBalance($request->input('opening_balance'));
        if ($openingBalance === null) {
            return response()->json(['error' => __('Enter a valid opening balance.')], 422);
        }
        $creatorId = \Auth::user()->creatorId();
        $actor = PosActorService::restaurantActor();
        if (! $actor) {
            return response()->json(['error' => __('pos.pin_sign_in_required')], 403);
        }
        $userId = $actor->id;
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
        if ($msg = BusinessDayLockService::assertNotLocked($creatorId, $today)) {
            return response()->json(['error' => $msg], 422);
        }
        $dayClosed = \App\Models\PosDayClosed::where('closed_date', $today)->where('created_by', $creatorId)->first();
        if ($dayClosed) {
            $isOwner = (\Auth::user()->creatorId() === \Auth::user()->id);
            if (!$isOwner) {
                return response()->json(['error' => __('Day is closed. Only an administrator can reopen.')], 422);
            }
        }
        $opShift = $request->input('operational_shift');
        $existing = PosShift::where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('operational_shift', $opShift)
            ->where('status', PosShift::STATUS_OPEN)
            ->first();
        if ($existing) {
            return response()->json(['error' => __('You already have an open :shift shift for this branch.', ['shift' => AttendanceShiftCatalog::operationalShiftOptions()[$opShift] ?? $opShift])], 422);
        }
        $shift = new PosShift();
        $shift->branch_id = $branchId;
        $shift->user_id = $userId;
        $shift->operational_shift = $opShift;
        $shift->opened_at = now();
        $shift->opening_balance = $openingBalance;
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
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $openingBalance = $this->resolveOpeningBalance($request->input('opening_balance'));
            if ($openingBalance === null) {
                return redirect()->back()->with('error', __('Enter a valid opening balance.'));
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
            $shift->opening_balance = $openingBalance;
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
        $drop->user_id = PosActorService::posActorId();
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
                $cashIn->user_id = PosActorService::posActorId();
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
                $expense->user_id = PosActorService::posActorId();
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
        $metrics = app(PosShiftReportService::class)->buildShiftMetrics($pos_shift, \Auth::user()->creatorId());

        return view('pos_shift.report', array_merge($metrics, [
            'pos_shift' => $pos_shift,
        ]));
    }

    public function closeForm(PosShift $pos_shift)
    {
        if (!\Auth::user()->canManagePos() || $pos_shift->created_by != \Auth::user()->creatorId()) {
            return redirect()->route('pos-shift.index')->with('error', __('Permission denied.'));
        }

        // Already closed: do not use redirect()->back() (Referer is often this same URL → redirect loop).
        if ($pos_shift->status !== PosShift::STATUS_OPEN) {
            return redirect()->route('pos-shift.show', $pos_shift)
                ->with('error', __('This shift is already closed.'));
        }

        $pos_shift->load(['branch', 'user', 'cashDrops', 'cashIns']);
        $metrics = app(PosShiftReportService::class)->buildShiftMetrics($pos_shift, \Auth::user()->creatorId());

        return view('pos_shift.close', [
            'pos_shift' => $pos_shift,
            'salesTotal' => $metrics['salesTotal'] ?? 0,
            'expectedCash' => $metrics['expectedCash'] ?? 0,
        ]);
    }

    public function close(Request $request, PosShift $pos_shift)
    {
        if (!\Auth::user()->canManagePos() || $pos_shift->created_by != \Auth::user()->creatorId()) {
            return redirect()->route('pos-shift.index')->with('error', __('Permission denied.'));
        }

        // Idempotent: double-submit / refresh after close must not flash "Permission denied" or redirect()->back().
        if ($pos_shift->status !== PosShift::STATUS_OPEN) {
            return redirect()->route('pos-shift.index')
                ->with('success', __('This shift is already closed.'));
        }

        $request->validate([
            'closing_balance' => 'required|numeric|min:0',
            'tips' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ], [
            'closing_balance.min' => __('Closing balance must be zero or positive (physical cash counted in the drawer).'),
        ]);

        $posIds = app(PosShiftReportService::class)->resolveShiftPosIds($pos_shift, \Auth::user()->creatorId());
        $paidPosIds = Pos::whereIn('id', $posIds)->whereHas('posPayments')->pluck('id');
        $salesTotal = PosPayment::whereIn('pos_id', $posIds)->sum('amount');
        $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', \App\Models\PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $balanceCash = (float) \App\Models\InvoicePayment::where('pos_shift_id', $pos_shift->id)->where('payment_method', 0)->sum('amount');
        $cashTotal += $balanceCash;
        $cardTotal = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', [\App\Models\PosPayment::METHOD_CARD, \App\Models\PosPayment::METHOD_MPESA, \App\Models\PosPayment::METHOD_BANK_TRANSFER, 'visa', 'cheque'])->sum('amount');
        $creditTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
        $closingBalance = (float) $request->closing_balance;
        $metrics = app(PosShiftReportService::class)->buildShiftMetrics($pos_shift, \Auth::user()->creatorId());
        $expectedCash = (float) ($metrics['expectedCash'] ?? 0);
        $difference = round($closingBalance - $expectedCash, 2);
        if (abs($difference) >= 0.01 && trim((string) $request->input('notes')) === '') {
            return redirect()->back()->withInput()->withErrors([
                'notes' => __('pos.close_reason_required'),
            ]);
        }

        $pos_shift->closed_at = now();
        $pos_shift->closing_balance = $closingBalance;
        $pos_shift->cash_total = $cashTotal;
        $pos_shift->card_total = $cardTotal;
        $pos_shift->credit_total = $creditTotal;
        $pos_shift->difference = $difference;
        $pos_shift->tips = (float) ($request->tips ?? 0);
        $pos_shift->notes = $request->notes;
        $pos_shift->sales_count = $paidPosIds->count();
        $pos_shift->sales_total = $salesTotal;
        $pos_shift->status = PosShift::STATUS_CLOSED;
        $pos_shift->save();
        PosAuditLogService::log(PosAuditLog::ACTION_CLOSE_SHIFT, 'pos_shift', (int) $pos_shift->id, __('Shift closed'), ['branch_id' => $pos_shift->branch_id, 'closing_balance' => $closingBalance, 'difference' => $difference]);
        $this->releaseShiftTables($pos_shift);

        return redirect()->route('pos-shift.show', $pos_shift)->with('success', __('Shift closed successfully.'));
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
     * Send the shift-closed and end-of-day emails after the shift is already closed.
     */
    public function sendClosedEmail(PosShift $pos_shift)
    {
        if (! \Auth::user()->canManagePos() || $pos_shift->created_by != \Auth::user()->creatorId()) {
            return redirect()->route('pos-shift.index')->with('error', __('Permission denied.'));
        }

        if ($pos_shift->status !== PosShift::STATUS_CLOSED) {
            return redirect()->route('pos-shift.show', $pos_shift)->with('error', __('Close the shift before sending the email.'));
        }

        $metrics = app(PosShiftReportService::class)->buildShiftMetrics($pos_shift, \Auth::user()->creatorId());
        $expectedCash = (float) ($metrics['expectedCash'] ?? 0);
        $this->sendShiftClosedEmailToManagers($pos_shift, $expectedCash);
        PosEodReportAlerts::sendOnShiftClose($pos_shift);

        return redirect()->route('pos-shift.show', $pos_shift)->with('success', __('Shift email sent.'));
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
                    LoggedMail::send($email, new InternalOperationalAlertMail($subject, $body), [
                        'source' => 'pos_shift_report',
                        'subject' => $subject,
                        'created_by' => $creatorId,
                    ]);
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Read an opening amount typed as 1500, 1.500, or 1.500,00.
     * Returns null when the text is not an amount.
     */
    private function resolveOpeningBalance(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }
        if (is_int($raw) || is_float($raw)) {
            return $raw < 0 ? null : round((float) $raw, 2);
        }

        $amount = preg_replace('/\s+/', '', trim((string) $raw)) ?? '';
        if ($amount === '') {
            return 0.0;
        }
        if (str_contains($amount, ',')) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $amount)) {
            $amount = str_replace('.', '', $amount);
        }
        if (! is_numeric($amount)) {
            return null;
        }

        $value = (float) $amount;

        return $value < 0 ? null : round($value, 2);
    }
}
