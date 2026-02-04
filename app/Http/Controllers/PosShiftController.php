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
        if (\Auth::user()->can('manage pos')) {
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
        if (\Auth::user()->can('manage pos')) {
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
        if (\Auth::user()->can('manage pos')) {
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
        if (\Auth::user()->can('manage pos') && $pos_shift->created_by == \Auth::user()->creatorId()) {
            $pos_shift->load(['branch', 'user', 'tableAssignments.table', 'tableAssignments.user']);
            return view('pos_shift.show', compact('pos_shift'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function closeForm(PosShift $pos_shift)
    {
        if (\Auth::user()->can('manage pos') && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            return view('pos_shift.close', compact('pos_shift'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function close(Request $request, PosShift $pos_shift)
    {
        if (\Auth::user()->can('manage pos') && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $posIds = Pos::where('shift_id', $pos_shift->id)->pluck('id');
            $salesTotal = PosPayment::whereIn('pos_id', $posIds)->sum('discount_amount');
            $pos_shift->closed_at = now();
            $pos_shift->closing_balance = (float) $request->closing_balance;
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
        if (\Auth::user()->can('manage pos') && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
            $pos_shift->load('branch');
            $tables = PosTable::where('branch_id', $pos_shift->branch_id)->where('created_by', \Auth::user()->creatorId())->with('area')->orderBy('area_id')->orderBy('name')->get();
            $assigned = PosTableAssignment::where('shift_id', $pos_shift->id)->whereNull('unassigned_at')->pluck('table_id')->toArray();
            return view('pos_shift.assign_table', compact('pos_shift', 'tables', 'assigned'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function assignTable(Request $request, PosShift $pos_shift)
    {
        if (\Auth::user()->can('manage pos') && $pos_shift->created_by == \Auth::user()->creatorId() && $pos_shift->status === PosShift::STATUS_OPEN) {
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
}
