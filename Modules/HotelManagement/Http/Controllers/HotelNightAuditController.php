<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelNightAudit;
use Modules\HotelManagement\Entities\HotelStay;

class HotelNightAuditController extends Controller
{
    public function index()
    {
        if (!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $audits = HotelNightAudit::where('created_by', Auth::user()->creatorId())
            ->orderByDesc('audit_date')
            ->orderByDesc('id')
            ->get();
        return view('hotelmanagement::night_audit.index', compact('audits'));
    }

    public function run(Request $request)
    {
        if (!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $auditDate = $request->get('audit_date', date('Y-m-d'));
        $creatorId = Auth::user()->creatorId();
        $branchId = Auth::user()->activeBranchContextId();

        $alreadyClosed = HotelNightAudit::where('created_by', $creatorId)
            ->where('branch_id', $branchId)
            ->whereDate('audit_date', $auditDate)
            ->where('status', 'closed')
            ->exists();
        if ($alreadyClosed) {
            return redirect()->back()->with('error', __('Night audit already closed for this date.'));
        }

        DB::transaction(function () use ($creatorId, $branchId, $auditDate) {
            $roomRevenue = (float) HotelFolio::where('created_by', $creatorId)
                ->whereDate('created_at', $auditDate)
                ->sum('grand_total');
            $serviceRevenue = (float) HotelFolio::where('created_by', $creatorId)
                ->whereDate('created_at', $auditDate)
                ->withSum(['lines as service_lines_total' => function ($q) {
                    $q->where('line_type', 'pos_charge');
                }], 'line_total')
                ->get()
                ->sum('service_lines_total');

            HotelNightAudit::create([
                'branch_id' => $branchId,
                'audit_date' => $auditDate,
                'room_revenue' => $roomRevenue,
                'service_revenue' => $serviceRevenue,
                'total_revenue' => $roomRevenue + $serviceRevenue,
                'status' => 'closed',
                'created_by' => $creatorId,
            ]);

            HotelFolio::where('created_by', $creatorId)
                ->where('status', 'open')
                ->whereDate('created_at', '<=', $auditDate)
                ->update(['status' => 'settled']);

            HotelStay::where('created_by', $creatorId)
                ->where('status', 'checked_out')
                ->whereDate('checked_out_at', '<=', $auditDate)
                ->update(['notes' => DB::raw("CONCAT(COALESCE(notes,''), ' | Audit closed')")]);
        });

        return redirect()->route('hotel.night-audit.index')->with('success', __('Night audit closed successfully.'));
    }
}
