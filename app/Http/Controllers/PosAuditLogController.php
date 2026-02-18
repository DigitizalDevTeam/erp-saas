<?php

namespace App\Http\Controllers;

use App\Models\PosAuditLog;
use Illuminate\Http\Request;

/**
 * Phase 4: View POS audit log (managers).
 */
class PosAuditLogController extends Controller
{
    public function index(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        if (!\Auth::user()->can('pos restaurant manager') && !\Auth::user()->can('pos restaurant full') && !\Auth::user()->can('manage pos')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $query = PosAuditLog::where('created_by', \Auth::user()->creatorId())
            ->with('user')
            ->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate(50);
        return view('pos_audit_log.index', compact('logs'));
    }
}
