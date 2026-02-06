<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Pos;
use App\Models\PosArea;
use App\Models\PosPayment;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\PosTableAssignment;
use App\Models\User;
use Illuminate\Http\Request;

class PosShiftController extends Controller
{
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
            $pos_shift->load(['branch', 'user', 'tableAssignments.table', 'tableAssignments.user']);
            return view('pos_shift.show', compact('pos_shift'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function closeForm(PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            return view('pos_shift.close', compact('pos_shift'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function close(Request $request, PosShift $pos_shift)
    {
        if (\Auth::user()->canManagePos() && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $posIds = Pos::where('shift_id', $pos_shift->id)->pluck('id');
            $salesTotal = PosPayment::whereIn('pos_id', $posIds)->sum('amount');
            // Phase 4: Cash-up totals by payment type (treat null payment_method as cash for backward compat)
            $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
                $q->where('payment_method', \App\Models\PosPayment::METHOD_CASH)->orWhereNull('payment_method');
            })->sum('amount');
            $cardTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CARD)->sum('amount');
            $creditTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', \App\Models\PosPayment::METHOD_CREDIT)->sum('amount');
            $closingBalance = (float) $request->closing_balance;
            $difference = ($pos_shift->opening_balance + $cashTotal) - $closingBalance;

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
            PosTableAssignment::where('shift_id', $pos_shift->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
            return redirect()->route('pos-shift.index')->with('success', __('Shift closed successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
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
}
