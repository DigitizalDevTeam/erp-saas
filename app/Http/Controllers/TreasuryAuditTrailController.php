<?php

namespace App\Http\Controllers;

use App\Services\TreasuryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 9 — audit trail (plan §9, §10 menu).
 *
 * Every Treasury service writes a DimensionAuditEvent row for every
 * state change, so this one table is the complete, append-only
 * record — the screen reads, it never writes. Filterable by
 * entity, action, actor and date; company-scoped, newest first
 * (same pattern as the Cost Centre audit trail).
 */
class TreasuryAuditTrailController extends Controller
{
    public function __construct(protected TreasuryReportService $reports = new TreasuryReportService())
    {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $filters['actor_id'] = $request->input('actor_id');

        $trail = $this->reports->auditTrail(
            $creatorId,
            $filters,
            (int) $request->input('page', 1)
        );

        return view('treasury.audit-trail.index', [
            'events' => $trail['paginator'],
            'actors' => $trail['actors'],
            'entityTypes' => $trail['entity_types'],
            'actions' => $trail['actions'],
            'selectedEntityType' => $filters['type'],
            'selectedAction' => $filters['state'],
            'selectedActor' => $filters['actor_id'] !== null && ctype_digit((string) $filters['actor_id'])
                ? (int) $filters['actor_id'] : null,
            'from' => $filters['from'],
            'to' => $filters['to'],
        ]);
    }
}
