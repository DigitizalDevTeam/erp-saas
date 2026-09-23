<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosArea;
use App\Models\PosAuditLog;
use App\Models\PosTable;
use App\Services\PosActorService;
use App\Services\PosAuditLogService;
use Illuminate\Http\Request;

class PosTableController extends Controller
{
    public function index()
    {
        if (\Auth::user()->canManagePos()) {
            $tables = PosTable::where('created_by', \Auth::user()->creatorId())
                ->with(['area', 'branch'])
                ->orderBy('branch_id')
                ->orderBy('area_id')
                ->orderBy('name')
                ->get();
            return view('pos_table.index', compact('tables'));
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
            $areas = [];
            return view('pos_table.create', compact('branches', 'areas'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'area_id' => 'required|exists:pos_areas,id',
                'branch_id' => 'required|exists:branches,id',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $area = PosArea::where('id', $request->area_id)
                ->where('created_by', \Auth::user()->creatorId())
                ->first();
            if (! $area || (int) $area->branch_id !== (int) $request->branch_id) {
                return redirect()->back()->with('error', __('Area must belong to the selected branch.'));
            }
            $table = new PosTable();
            $table->area_id = $request->area_id;
            $table->branch_id = $request->branch_id;
            $table->name = $request->name;
            $table->capacity = (int) ($request->capacity ?? 4);
            $table->status = $request->status ?? PosTable::STATUS_AVAILABLE;
            $table->created_by = \Auth::user()->creatorId();
            $table->save();
            return redirect()->route('pos-table.index')->with('success', __('Table created successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function edit(PosTable $pos_table)
    {
        if (\Auth::user()->canManagePos() && $pos_table->created_by == \Auth::user()->creatorId()) {
            $branches = Branch::where('created_by', \Auth::user()->creatorId())
                ->where('is_active', true)
                ->where('pos_type', Branch::POS_TYPE_RESTAURANT)
                ->get()
                ->pluck('name', 'id');
            $areas = PosArea::where('created_by', \Auth::user()->creatorId())
                ->where('branch_id', $pos_table->branch_id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->pluck('name', 'id');
            return view('pos_table.edit', compact('pos_table', 'branches', 'areas'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, PosTable $pos_table)
    {
        if (\Auth::user()->canManagePos() && $pos_table->created_by == \Auth::user()->creatorId()) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'area_id' => 'required|exists:pos_areas,id',
                'branch_id' => 'required|exists:branches,id',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $area = PosArea::where('id', $request->area_id)
                ->where('created_by', \Auth::user()->creatorId())
                ->first();
            if (! $area || (int) $area->branch_id !== (int) $request->branch_id) {
                return redirect()->back()->with('error', __('Area must belong to the selected branch.'));
            }
            $pos_table->area_id = $request->area_id;
            $pos_table->branch_id = $request->branch_id;
            $pos_table->name = $request->name;
            $pos_table->capacity = (int) ($request->capacity ?? 4);
            $pos_table->status = $request->status ?? $pos_table->status;
            $pos_table->save();
            return redirect()->route('pos-table.index')->with('success', __('Table updated successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function destroy(PosTable $pos_table)
    {
        if (\Auth::user()->canManagePos() && $pos_table->created_by == \Auth::user()->creatorId()) {
            if ($pos_table->posSales()->count() > 0) {
                return redirect()->back()->with('error', __('Table has sales history and cannot be deleted.'));
            }
            $pos_table->delete();
            return redirect()->route('pos-table.index')->with('success', __('Table deleted successfully.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function getTablesByBranch(Request $request)
    {
        $branchId = $request->branch_id;
        $tables = PosTable::where('created_by', \Auth::user()->creatorId())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->with('area')
            ->orderBy('name')
            ->get()
            ->map(fn($t) => ['id' => $t->id, 'name' => $t->name . ($t->area ? ' (' . $t->area->name . ')' : ''), 'status' => $t->status])
            ->toArray();
        return response()->json($tables);
    }

    public function getTablesByArea(Request $request)
    {
        $areaId = $request->area_id;
        $tables = PosTable::where('created_by', \Auth::user()->creatorId())
            ->when($areaId, fn($q) => $q->where('area_id', $areaId))
            ->orderBy('name')
            ->get()
            ->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status])
            ->toArray();
        return response()->json($tables);
    }

    /**
     * Update table status (available / occupied / reserved).
     * Open table (available → occupied) is allowed for any POS user (waiter/cashier/manager). No manager password needed.
     */
    public function updateStatus(Request $request, $id)
    {
        if (!\Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $table = PosTable::where('id', $id)->where('created_by', \Auth::user()->creatorId())->first();
        if (!$table) {
            return response()->json(['error' => __('Table not found.')], 404);
        }
        $status = $request->input('status');
        if (!in_array($status, [PosTable::STATUS_AVAILABLE, PosTable::STATUS_OCCUPIED, PosTable::STATUS_RESERVED], true)) {
            return response()->json(['error' => __('Invalid status.')], 422);
        }
        $oldStatus = $table->status;
        $table->status = $status;
        if ($request->has('guests_count')) {
            $guests = $request->input('guests_count');
            $table->current_guests = ($guests !== null && $guests !== '') ? (int) $guests : null;
        }
        if ($request->has('customer_name')) {
            $table->customer_name = $request->input('customer_name') ? trim($request->input('customer_name')) : null;
        }
        if ($request->has('waiter_user_id')) {
            $waiterUserId = (int) $request->input('waiter_user_id');
            if ($waiterUserId > 0) {
                $creatorId = (int) \Auth::user()->creatorId();
                $waiter = \App\Models\User::where('id', $waiterUserId)
                    ->where(function ($q) use ($creatorId) {
                        $q->where('created_by', $creatorId)->orWhere('id', $creatorId);
                    })
                    ->first();
                if (! $waiter) {
                    return response()->json(['error' => __('Invalid waiter/user.')], 422);
                }
                $table->waiter_user_id = $waiter->id;
            } else {
                $table->waiter_user_id = null;
            }
        } elseif ($oldStatus === PosTable::STATUS_AVAILABLE && $status === PosTable::STATUS_OCCUPIED && ! $table->waiter_user_id) {
            $table->waiter_user_id = PosActorService::posActorId() ?: null;
        }
        if ($status === PosTable::STATUS_AVAILABLE) {
            $table->current_guests = null;
            $table->customer_name = null;
            $table->waiter_user_id = null;
        }
        $table->save();
        if ($oldStatus === PosTable::STATUS_AVAILABLE && $status === PosTable::STATUS_OCCUPIED) {
            PosAuditLogService::log(PosAuditLog::ACTION_OPEN_TABLE, 'pos_table', (int) $table->id, __('Table opened'), ['table_name' => $table->name, 'guests' => $table->current_guests, 'waiter_user_id' => $table->waiter_user_id]);
        } elseif ($status === PosTable::STATUS_OCCUPIED) {
            PosAuditLogService::log(PosAuditLog::ACTION_OPEN_TABLE, 'pos_table', (int) $table->id, __('Table opened'), ['table_name' => $table->name, 'guests' => $table->current_guests, 'waiter_user_id' => $table->waiter_user_id]);
        } elseif ($status === PosTable::STATUS_AVAILABLE) {
            PosAuditLogService::log(PosAuditLog::ACTION_CLOSE_TABLE, 'pos_table', (int) $table->id, __('Table closed'), ['table_name' => $table->name]);
        }
        $table->loadMissing('waiterUser');
        return response()->json([
            'success' => true,
            'status' => $table->status,
            'current_guests' => $table->current_guests,
            'customer_name' => $table->customer_name,
            'waiter_user_id' => $table->waiter_user_id,
            'waiter_name' => $table->waiterUser ? $table->waiterUser->name : null,
        ]);
    }

    /**
     * Phase 4: Close table — set status to available. Only Cashier/Manager (or user with full/manager POS restaurant).
     */
    public function closeTable($id)
    {
        if (! \Auth::user()->canManagePos()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $actor = PosActorService::restaurantActor();
        if (! $actor) {
            return response()->json(['error' => __('pos.pin_sign_in_required')], 403);
        }
        // Restrict to Cashier/Manager: must have full, manager, or global manage pos
        if (! $actor->can('pos restaurant full') && ! $actor->can('pos restaurant manager') && ! $actor->can('manage pos')) {
            return response()->json(['error' => __('Only Cashier or Manager can close tables.')], 403);
        }
        $table = PosTable::where('id', $id)->where('created_by', $actor->creatorId())->first();
        if (!$table) {
            return response()->json(['error' => __('Table not found.')], 404);
        }
        $table->status = PosTable::STATUS_AVAILABLE;
        $table->current_guests = null;
        $table->customer_name = null;
        $table->waiter_user_id = null;
        $table->save();
        PosAuditLogService::log(PosAuditLog::ACTION_CLOSE_TABLE, 'pos_table', (int) $table->id, __('Table closed (force)'), ['table_name' => $table->name]);
        return response()->json(['success' => true, 'status' => $table->status]);
    }
}
