<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Utility;
use App\Services\BusinessDayLockService;
use App\Services\NightAuditOrchestratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelNightAudit;
use Modules\HotelManagement\Entities\HotelStay;

class HotelNightAuditController extends Controller
{
    private function canView(): bool
    {
        return Auth::user()->can('show hotel frontdesk') || Auth::user()->can('manage hotel frontdesk') || Auth::user()->can('manage hotel');
    }

    private function canManage(): bool
    {
        return Auth::user()->can('manage hotel frontdesk') || Auth::user()->can('manage hotel');
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $settings = Utility::settingsById($creatorId);
        $nightAuditTime = NightAuditOrchestratorService::configuredNightAuditTime($creatorId);

        $audits = HotelNightAudit::where('created_by', $creatorId)
            ->orderByDesc('audit_date')
            ->orderByDesc('id')
            ->get();
        $canManage = $this->canManage();

        return view('hotelmanagement::night_audit.index', compact('audits', 'canManage', 'nightAuditTime', 'settings'));
    }

    public function run(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $auditDate = $request->get('audit_date', date('Y-m-d', strtotime('-1 day')));
        $creatorId = Auth::user()->creatorId();
        $branchId = Auth::user()->activeBranchContextId();

        if (BusinessDayLockService::isLocked($creatorId, $auditDate)) {
            return redirect()->back()->with('error', __('Night audit already completed for this date.'));
        }

        $alreadyClosed = HotelNightAudit::where('created_by', $creatorId)
            ->where('branch_id', $branchId)
            ->whereDate('audit_date', $auditDate)
            ->where('status', 'closed')
            ->exists();
        if ($alreadyClosed) {
            return redirect()->back()->with('error', __('Night audit already closed for this date.'));
        }

        $posResult = NightAuditOrchestratorService::closeBusinessDay($creatorId, $auditDate, $branchId, (int) Auth::id());

        DB::transaction(function () use ($creatorId, $branchId, $auditDate, $posResult) {
            $roomRevenue = (float) HotelFolio::where('created_by', $creatorId)
                ->whereDate('created_at', $auditDate)
                ->sum('grand_total');
            $serviceRevenue = (float) HotelFolio::where('created_by', $creatorId)
                ->whereDate('created_at', $auditDate)
                ->withSum(['lines as service_lines_total' => function ($q) {
                    $q->whereIn('line_type', ['pos_charge', 'misc_charge']);
                }], 'line_total')
                ->get()
                ->sum('service_lines_total');

            $summary = array_merge($posResult['summary'], [
                'room_revenue' => $roomRevenue,
                'service_revenue' => $serviceRevenue,
                'hotel_total' => $roomRevenue + $serviceRevenue,
            ]);

            HotelNightAudit::create([
                'branch_id' => $branchId,
                'audit_date' => $auditDate,
                'room_revenue' => $roomRevenue,
                'service_revenue' => $serviceRevenue,
                'total_revenue' => $roomRevenue + $serviceRevenue,
                'pos_sales_total' => $posResult['pos_sales_total'],
                'pos_payments_total' => $posResult['pos_payments_total'],
                'summary_json' => $summary,
                'status' => 'closed',
                'created_by' => $creatorId,
                'closed_by' => Auth::id(),
            ]);

            HotelStay::where('created_by', $creatorId)
                ->where('status', 'checked_out')
                ->whereDate('checked_out_at', '<=', $auditDate)
                ->update(['notes' => DB::raw("CONCAT(COALESCE(notes,''), ' | Audit closed')")]);
        });

        return redirect()->route('hotel.night-audit.index')->with(
            'success',
            __('Night audit completed. :count POS shift(s) closed. Day is locked.', ['count' => $posResult['shifts_closed']])
        );
    }
}
