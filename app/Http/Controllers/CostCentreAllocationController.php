<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Models\CostCentre;
use App\Models\CostCentreAllocation;
use App\Models\CostCentreAllocationTemplate;
use App\Services\CostCentreAllocationService;
use App\Services\CostCentreService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CostCentreAllocationController extends Controller
{
    public function __construct(
        protected CostCentreAllocationService $service,
        protected CostCentreService $centres = new CostCentreService(),
    ) {}

    /** List of allocations for the company (posted, reversed, drafts). */
    public function index()
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $allocations = CostCentreAllocation::forCompany($creatorId)
            ->with(['lines.costCentre'])
            ->orderByDesc('id')
            ->paginate(25);

        return view('cost-centres.allocations.index', compact('allocations'));
    }

    /**
     * Validation endpoint for the allocation modal: returns the computed
     * split + errors WITHOUT posting anything.
     */
    public function validateSplit(Request $request)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $creatorId = Auth::user()->creatorId();
        $amount = (string) ($request->input('source_amount') ?? '');
        $basis = (string) ($request->input('basis', 'percentage'));
        $lines = $this->normaliseInputLines($request->input('lines', []));
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();

        try {
            $computed = $this->service->preview($amount, $lines, $basis, $creatorId, $date);

            return response()->json([
                'ok' => true,
                'lines' => $computed['lines'],
                'total_allocated' => $computed['total_allocated'],
                'source_amount' => $computed['source_amount'],
                'rounding_difference' => $computed['rounding_difference'],
                'warnings' => $computed['warnings'],
            ]);
        } catch (CostCentreException $e) {
            return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
        }
    }

    /** Centres the modal can offer: leaves that can take transactions. */
    public function centres()
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $creatorId = Auth::user()->creatorId();
        $centres = CostCentre::forCompany($creatorId)->orderBy('code')->get()
            ->filter(fn ($c) => $this->centres->canAcceptTransactions($c))
            ->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name])
            ->values();

        return response()->json(['ok' => true, 'centres' => $centres]);
    }

    /** The posted allocation for one source line (for the modal). */
    public function byLine(Request $request)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $creatorId = Auth::user()->creatorId();
        $allocation = $this->service->postedForLine(
            (string) $request->input('source_type'),
            (int) $request->input('source_id'),
            (string) $request->input('source_line_type'),
            (int) $request->input('source_line_id'),
            $creatorId
        );

        if (! $allocation) {
            return response()->json(['ok' => true, 'allocation' => null]);
        }

        return response()->json(['ok' => true, 'allocation' => [
            'id' => (int) $allocation->id,
            'basis' => $allocation->basis,
            'source_amount' => (string) $allocation->source_amount,
            'status' => $allocation->status,
            'lines' => $allocation->lines->map(fn ($l) => [
                'cost_centre_id' => (int) $l->cost_centre_id,
                'percentage' => $l->percentage !== null ? (string) $l->percentage : null,
                'fixed_amount' => $l->fixed_amount !== null ? (string) $l->fixed_amount : null,
                'allocated_amount' => (string) $l->allocated_amount,
            ])->all(),
        ]]);
    }

    /**
     * Post an allocation for a bill/expense line. A posted allocation
     * already on the line is reversed first (reverse-and-repost only —
     * a posted split is never edited in place).
     */
    public function store(Request $request)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();
        $userId = Auth::id();

        $sourceType = (string) $request->input('source_type');
        $sourceId = (int) $request->input('source_id');
        $lineType = (string) $request->input('source_line_type');
        $lineId = (int) $request->input('source_line_id');
        $basis = (string) ($request->input('basis', 'percentage'));
        $lines = $this->normaliseInputLines($request->input('lines', []));
        $templateId = $request->filled('template_id') ? (int) $request->input('template_id') : null;

        try {
            // Validate the NEW split before touching the old one, so a
            // bad split cannot leave the line with no allocation.
            $bill = $this->service->resolveSourceBill($sourceType, $sourceId, $creatorId);
            $amount = $this->service->lineAmount($bill, $lineType, $lineId);
            $date = $bill->bill_date ? Carbon::parse($bill->bill_date) : Carbon::today();
            $this->service->preview($amount, $lines, $basis, $creatorId, $date);

            $existing = $this->service->postedForLine($sourceType, $sourceId, $lineType, $lineId, $creatorId);
            $replacesId = $existing ? (int) $existing->id : null;

            if ($existing) {
                $this->service->reverse($existing, $creatorId, $userId, __('Replaced by a new allocation from the allocation modal.'));
            }

            $allocation = $this->service->createPosted(
                $sourceType, $sourceId, $lineType, $lineId, $basis, $lines,
                $creatorId, $userId, $templateId, $replacesId
            );
        } catch (CostCentreException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'allocation' => [
                    'id' => (int) $allocation->id,
                    'status' => $allocation->status,
                    'source_amount' => (string) $allocation->source_amount,
                    'lines' => $allocation->lines->map(fn ($l) => [
                        'cost_centre_id' => (int) $l->cost_centre_id,
                        'percentage' => $l->percentage !== null ? (string) $l->percentage : null,
                        'fixed_amount' => $l->fixed_amount !== null ? (string) $l->fixed_amount : null,
                        'allocated_amount' => (string) $l->allocated_amount,
                    ])->all(),
                ],
            ]);
        }

        return redirect()->route('cost-centre-allocations.index')
            ->with('success', __('Allocation successfully posted.'));
    }

    /** Reverse a posted allocation (reason required). */
    public function reverse(Request $request, CostCentreAllocation $allocation)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();

        try {
            $this->service->reverse($allocation, $creatorId, Auth::id(), (string) $request->input('reason', ''));
        } catch (CostCentreException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back()->with('success', __('Allocation successfully reversed.'));
    }

    /** Approve a posted allocation — never your own. */
    public function approve(Request $request, CostCentreAllocation $allocation)
    {
        if (! Auth::user()->can('approve cost centre allocations')) {
            return $this->deny($request);
        }

        try {
            $this->service->approve($allocation, Auth::user()->creatorId(), Auth::id());
        } catch (CostCentreException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back()->with('success', __('Allocation successfully approved.'));
    }

    // ------------------------------------------------------------------
    // Templates (JSON for the modal + simple list management)
    // ------------------------------------------------------------------

    public function templates(Request $request)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return $this->deny($request);
        }

        $templates = CostCentreAllocationTemplate::forCompany(Auth::user()->creatorId())
            ->orderBy('name')->get()
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'basis' => $t->basis,
                'lines' => $t->lines,
            ])->all();

        return response()->json(['ok' => true, 'templates' => $templates]);
    }

    public function storeTemplate(Request $request)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        try {
            $template = $this->service->createTemplate(
                (string) $request->input('name', ''),
                (string) ($request->input('basis', 'percentage')),
                $this->normaliseInputLines($request->input('lines', [])),
                Auth::user()->creatorId(),
                Auth::id()
            );
        } catch (CostCentreException $e) {
            return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
        }

        return response()->json(['ok' => true, 'template' => [
            'id' => (int) $template->id,
            'name' => $template->name,
            'basis' => $template->basis,
            'lines' => $template->lines,
        ]]);
    }

    public function destroyTemplate(Request $request, CostCentreAllocationTemplate $template)
    {
        if (! Auth::user()->can('manage cost centre allocations')) {
            return $this->deny($request);
        }

        if ((int) $template->created_by !== Auth::user()->creatorId()) {
            abort(403, __('This template belongs to another company.'));
        }

        $template->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back()->with('success', __('Template successfully deleted.'));
    }

    /** Normalise modal input lines to the engine's shape. */
    protected function normaliseInputLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $clean = [];
        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $clean[] = [
                'cost_centre_id' => (int) ($line['cost_centre_id'] ?? 0),
                'percentage' => isset($line['percentage']) && $line['percentage'] !== '' ? (string) $line['percentage'] : null,
                'fixed_amount' => isset($line['fixed_amount']) && $line['fixed_amount'] !== '' ? (string) $line['fixed_amount'] : null,
                'sort_order' => (int) ($line['sort_order'] ?? $index),
            ];
        }

        return $clean;
    }

    protected function deny(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        return redirect()->back()->with('error', __('Permission Denied.'));
    }
}
