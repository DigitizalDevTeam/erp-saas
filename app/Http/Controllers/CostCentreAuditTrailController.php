<?php

namespace App\Http\Controllers;

use App\Models\DimensionAuditEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CostCentreAuditTrailController extends Controller
{
    /**
     * Audit trail (Phase 6, plan §10 menu): every allocation,
     * assignment, approval, reversal and budget event for the company,
     * newest first. Append-only — the screen reads, it never writes.
     * Filterable by entity, action and date; scoped to the company,
     * so another company's events can never appear here.
     */
    public function index(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();

        $entityType = $request->filled('entity_type') ? (string) $request->input('entity_type') : null;
        $action = $request->filled('action') ? (string) $request->input('action') : null;
        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null;

        $query = DimensionAuditEvent::where('company_id', $creatorId)
            ->when($entityType !== null, fn ($q) => $q->where('entity_type', $entityType))
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('occurred_at', '<=', $to));

        $events = $query->orderByDesc('id')->paginate(25)->withQueryString();

        // Actor names, bulk-loaded once — never one query per event.
        $actors = User::whereIn('id', $events->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return view('cost-centres.audit-trail.index', [
            'events' => $events,
            'actors' => $actors,
            'entityTypes' => DimensionAuditEvent::where('company_id', $creatorId)->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'actions' => DimensionAuditEvent::where('company_id', $creatorId)->distinct()->orderBy('action')->pluck('action'),
            'selectedEntityType' => $entityType,
            'selectedAction' => $action,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]);
    }
}
