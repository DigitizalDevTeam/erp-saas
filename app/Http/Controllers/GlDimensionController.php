<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Exports\CostCentreReportExport;
use App\Models\Bill;
use App\Models\CostCentre;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Services\AllocationEngine;
use App\Services\CostCentreExportService;
use App\Services\CostCentreService;
use App\Services\DimensionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GlDimensionController extends Controller
{
    public function __construct(
        protected DimensionPostingService $service,
        protected CostCentreService $centres = new CostCentreService(),
    ) {}

    /**
     * GL Dimensions list (plan §8 menu): manual journals with their
     * dimension coverage — how many of each journal's lines carry a
     * cost centre split. Tagging a journal line never changes the
     * journal itself: dimensions sit beside the money.
     */
    public function index(Request $request)
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $journalsQuery = JournalEntry::where('created_by', $creatorId)
            ->orderByDesc('id');

        // Phase 6 exports: the same coverage the screen shows, as CSV /
        // XLSX / PDF. Gated by the export permission, like the reports.
        // Rows are built lazily (only when ?export= is present) and in
        // bulk — one query per table, never one per journal.
        $export = (new CostCentreExportService())->responseFor(
            $request, 'gl-dimensions', __('GL Dimensions'),
            __('Tagging never changes a journal amount.'),
            [__('Journal'), __('Date'), __('Reference'), __('Lines'), __('Tagged')],
            function () use ($journalsQuery, $creatorId) {
                $allJournals = $journalsQuery->get();
                $journalIds = $allJournals->pluck('id')->all();
                $itemsByJournal = JournalItem::whereIn('journal', $journalIds)->orderBy('id')->get()->groupBy('journal');
                $dimensionedSet = array_flip(JournalLineDimension::forCompany($creatorId)
                    ->where('line_type', JournalLineDimension::LINE_JOURNAL_ITEM)
                    ->whereIn('journal_id', $journalIds)
                    ->active()->distinct()->pluck('line_id')->all());

                $rows = [];
                foreach ($allJournals as $journal) {
                    $items = $itemsByJournal->get((int) $journal->id, collect());
                    $tagged = 0;
                    foreach ($items as $item) {
                        if (isset($dimensionedSet[(int) $item->id])) {
                            $tagged++;
                        }
                    }
                    $rows[] = ['#'.($journal->journal_id ?? $journal->id), (string) ($journal->date ?? ''), (string) ($journal->reference ?? ''), $items->count(), $tagged];
                }

                return $rows;
            }
        );
        if ($export !== null) {
            return $export;
        }

        $journals = $journalsQuery->paginate(25);

        $journalIds = $journals->pluck('id')->all();
        $itemsByJournal = JournalItem::whereIn('journal', $journalIds)
            ->orderBy('id')->get()->groupBy('journal');

        // Coverage, in bulk: which lines already carry dimensions.
        $dimensionedLineIds = JournalLineDimension::forCompany($creatorId)
            ->where('line_type', JournalLineDimension::LINE_JOURNAL_ITEM)
            ->whereIn('journal_id', $journalIds)
            ->active()
            ->distinct()
            ->pluck('line_id')
            ->all();
        $dimensionedSet = array_flip($dimensionedLineIds);

        $coverage = [];
        foreach ($itemsByJournal as $journalId => $items) {
            $tagged = 0;
            foreach ($items as $item) {
                if (isset($dimensionedSet[(int) $item->id])) {
                    $tagged++;
                }
            }
            $coverage[(int) $journalId] = ['lines' => $items->count(), 'tagged' => $tagged];
        }

        return view('cost-centres.gl-dimensions.index', compact('journals', 'itemsByJournal', 'coverage'));
    }

    /** Tag form for one journal: a split per line. */
    public function edit(JournalEntry $journal)
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        if ((int) $journal->created_by !== $creatorId) {
            abort(403, __('This journal belongs to another company.'));
        }

        $items = JournalItem::where('journal', $journal->id)->orderBy('id')->get();
        $dimensionsByLine = JournalLineDimension::forCompany($creatorId)
            ->forJournal((int) $journal->id)
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get()
            ->groupBy('line_id');
        $centres = $this->leafCentres($creatorId);
        $projects = $this->projects($creatorId);

        return view('cost-centres.gl-dimensions.edit', [
            'journal' => $journal,
            'items' => $items,
            'dimensionsByLine' => $dimensionsByLine,
            'centres' => $centres,
            'projects' => $projects,
        ]);
    }

    /** Apply the splits for a journal's lines (one split per line). */
    public function update(Request $request, JournalEntry $journal)
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();
        if ((int) $journal->created_by !== $creatorId) {
            abort(403, __('This journal belongs to another company.'));
        }

        try {
            $payload = $request->input('lines', []);
            if (! is_array($payload) || $payload === []) {
                throw new CostCentreException(__('No lines were submitted.'));
            }

            // One transaction for the whole edit (Phase 6 hardening):
            // without it, a failure halfway through (e.g. a closed
            // period on line 2) would leave line 1 already re-tagged
            // and line 2 untouched — a half-tagged journal. The
            // service calls nest into this transaction.
            DB::transaction(function () use ($payload, $creatorId) {
                foreach (array_values($payload) as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $lineId = (int) ($entry['line_id'] ?? 0);
                    if ($lineId <= 0) {
                        continue;
                    }
                    $basis = (string) ($entry['basis'] ?? 'percentage');
                    $lines = $this->normaliseInputLines($entry['lines'] ?? []);
                    $projectId = ! empty($entry['project_id']) ? (int) $entry['project_id'] : null;

                    if ($lines === []) {
                        // Empty split for this line: clear its tag.
                        $this->service->removeForLine(
                            JournalLineDimension::LINE_JOURNAL_ITEM, $lineId, $creatorId, Auth::id(),
                            __('Tag cleared from the journal edit form.')
                        );
                        continue;
                    }

                    $this->service->attachToLine(
                        JournalLineDimension::LINE_JOURNAL_ITEM, $lineId,
                        $lines, $basis, $creatorId, Auth::id(), $projectId
                    );
                }
            });
        } catch (CostCentreException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('gl-dimensions.index')
            ->with('success', __('Journal dimensions successfully saved. Tagging never changes the journal amounts.'));
    }

    /**
     * Validation endpoint for the edit form: returns the computed split
     * + errors WITHOUT saving anything. The amount always comes from
     * the posted line server-side, never from the client.
     */
    public function validateSplit(Request $request)
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $creatorId = Auth::user()->creatorId();
        $lineType = (string) ($request->input('line_type', JournalLineDimension::LINE_JOURNAL_ITEM));
        $lineId = (int) ($request->input('line_id', 0));
        $basis = (string) ($request->input('basis', 'percentage'));
        $lines = $this->normaliseInputLines($request->input('lines', []));

        try {
            $info = $this->service->lineInfo($lineType, $lineId, $creatorId);
            $computed = app(AllocationEngine::class)->compute($info['abs'], $lines, $basis);

            return response()->json([
                'ok' => true,
                'lines' => $computed['lines'],
                'total_allocated' => $computed['total_allocated'],
                'source_amount' => $computed['source_amount'],
                'rounding_difference' => $computed['rounding_difference'],
                'warnings' => [],
            ]);
        } catch (CostCentreException $e) {
            return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
        }
    }

    /** Centres the form can offer: leaves that can take transactions. */
    public function centres()
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $centres = $this->leafCentres(Auth::user()->creatorId())
            ->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name])
            ->values();

        return response()->json(['ok' => true, 'centres' => $centres]);
    }

    /**
     * Sync one bill's posted Phase 1 allocations onto its transaction
     * lines as journal dimensions. The allocation is the dimension
     * source of truth for a bill line — the split amounts are copied
     * as-is, never recomputed.
     */
    public function syncBill(Request $request, Bill $bill)
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();
        if ((int) $bill->created_by !== $creatorId) {
            abort(403, __('This bill belongs to another company.'));
        }

        try {
            $result = $this->service->syncForBill($bill, $creatorId, Auth::id());
        } catch (CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $message = __('Synced :synced of :total allocations (:rows dimension rows).', [
            'synced' => $result['synced'],
            'total' => $result['allocations'],
            'rows' => $result['rows'],
        ]);
        if ($result['warnings'] !== []) {
            $message .= ' '.implode(' ', $result['warnings']);
        }

        return redirect()->back()->with('success', $message);
    }

    /** Reverse form: pick the original journal and its reversal journal. */
    public function reverseForm()
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $journals = JournalEntry::where('created_by', $creatorId)
            ->orderByDesc('id')->limit(200)->get();

        return view('cost-centres.gl-dimensions.reverse', compact('journals'));
    }

    /** Mirror a journal's dimensions onto its reversing journal. */
    public function reverseStore(Request $request)
    {
        if (! Auth::user()->can('manage gl dimensions')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();
        $originalId = (int) $request->input('original_journal_id', 0);
        $reversalId = (int) $request->input('reversal_journal_id', 0);
        $reason = (string) $request->input('reason', '');

        try {
            $result = $this->service->reverseDimensionsForJournal($originalId, $reversalId, $creatorId, Auth::id(), $reason);
        } catch (CostCentreException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $message = __('Reversed :count dimension rows onto the reversal journal. The original rows stay readable.', ['count' => $result['reversed_rows']]);
        if ($result['warnings'] !== []) {
            $message .= ' '.implode(' ', $result['warnings']);
        }

        return redirect()->route('gl-dimensions.index')->with('success', $message);
    }

    /** Leaf centres that can take a transaction, for the company. */
    protected function leafCentres(int $creatorId)
    {
        return CostCentre::forCompany($creatorId)->orderBy('code')->get()
            ->filter(fn ($c) => $this->centres->canAcceptTransactions($c))
            ->values();
    }

    protected function projects(int $creatorId)
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('projects')) {
            return collect();
        }

        return \App\Models\Project::where('created_by', $creatorId)->orderBy('project_name')->get();
    }

    /** Normalise form input lines to the engine's shape. */
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
