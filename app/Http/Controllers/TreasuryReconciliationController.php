<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\BankAccount;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\TreasuryMissingMovement;
use App\Services\TreasuryReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §6, §7).
 *
 * All rules live in TreasuryReconciliationService; this controller
 * only gates permissions, scopes to the company and turns service
 * exceptions into redirects. The statement sessions themselves
 * still live under the existing bank-reconciliation routes; this
 * controller owns the Phase 7 additions: missing movements,
 * Treasury-operation matches and API sync.
 */
class TreasuryReconciliationController extends Controller
{
    protected TreasuryReconciliationService $service;

    public function __construct(?TreasuryReconciliationService $service = null)
    {
        $this->service = $service ?? new TreasuryReconciliationService();
    }

    // ------------------------------------------------------------------
    // Missing movements
    // ------------------------------------------------------------------

    public function missingMovementForm(BankReconciliationSession $bankReconciliationSession, BankStatementLine $line)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);
        if ((int) $line->session_id !== (int) $bankReconciliationSession->id) {
            abort(404);
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.reconciliation.missing-movement-form', [
            'session' => $bankReconciliationSession->load('bankAccount'),
            'line' => $line,
            'categories' => TreasuryMissingMovement::CATEGORIES,
            'accounts' => ChartOfAccount::where('created_by', $creatorId)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function proposeMissingMovement(Request $request, BankReconciliationSession $bankReconciliationSession, BankStatementLine $line)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);
        if ((int) $line->session_id !== (int) $bankReconciliationSession->id) {
            abort(404);
        }

        $data = $request->validate([
            'category' => ['required', Rule::in(TreasuryMissingMovement::CATEGORIES)],
            'offset_account_id' => 'required|exists:chart_of_accounts,id',
            'amount' => 'nullable|numeric|not_in:0',
            'exchange_rate' => 'nullable|numeric|min:0.0000000001',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->service->proposeMissingMovement(
                (int) Auth::user()->creatorId(),
                $bankReconciliationSession->id,
                $line->id,
                (int) Auth::id(),
                $data
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('bank-reconciliation.show', $bankReconciliationSession)
            ->with('success', __('Missing movement proposed. An approver must confirm it before anything posts.'));
    }

    public function confirmMissingMovement(Request $request, TreasuryMissingMovement $movement)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_APPROVE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeMovement($movement);

        $data = $request->validate([
            'posting_date' => 'nullable|date',
        ]);

        try {
            $this->service->confirmMissingMovement(
                (int) Auth::user()->creatorId(),
                $movement->id,
                (int) Auth::id(),
                $data
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank-reconciliation.show', $movement->session_id)
            ->with('success', __('Missing movement confirmed and posted. The statement line is now explained.'));
    }

    public function rejectMissingMovement(Request $request, TreasuryMissingMovement $movement)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeMovement($movement);

        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->service->rejectMissingMovement(
                (int) Auth::user()->creatorId(),
                $movement->id,
                (int) Auth::id(),
                $data['reason'] ?? null
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank-reconciliation.show', $movement->session_id)
            ->with('success', __('Missing movement rejected.'));
    }

    public function reverseMissingMovement(Request $request, TreasuryMissingMovement $movement)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_APPROVE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeMovement($movement);

        $data = $request->validate([
            'posting_date' => 'nullable|date',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->service->reverseMissingMovement(
                (int) Auth::user()->creatorId(),
                $movement->id,
                (int) Auth::id(),
                $data
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank-reconciliation.show', $movement->session_id)
            ->with('success', __('Missing movement reversed with an equal and opposite posting. The original stays.'));
    }

    // ------------------------------------------------------------------
    // Treasury-operation matches
    // ------------------------------------------------------------------

    public function matchTreasuryOperation(Request $request, BankReconciliationSession $bankReconciliationSession)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $data = $request->validate([
            'statement_line_id' => [
                'required',
                Rule::exists('bank_statement_lines', 'id')->where('session_id', $bankReconciliationSession->id),
            ],
            'treasury_operation_id' => 'required|exists:treasury_operations,id',
            'matched_amount' => 'nullable|numeric|min:0.0001',
        ]);

        try {
            $this->service->matchToTreasuryOperation(
                (int) Auth::user()->creatorId(),
                $bankReconciliationSession->id,
                (int) $data['statement_line_id'],
                (int) $data['treasury_operation_id'],
                (int) Auth::id(),
                isset($data['matched_amount']) ? (string) $data['matched_amount'] : null
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->back()->with('success', __('Treasury operation matched.'));
    }

    public function unmatchTreasuryOperation(Request $request, BankReconciliationSession $bankReconciliationSession)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->authorizeSession($bankReconciliationSession);

        $data = $request->validate([
            'statement_line_id' => [
                'required',
                Rule::exists('bank_statement_lines', 'id')->where('session_id', $bankReconciliationSession->id),
            ],
            'match_id' => 'nullable|integer',
        ]);

        try {
            $removed = $this->service->unmatchTreasuryOperation(
                (int) Auth::user()->creatorId(),
                $bankReconciliationSession->id,
                (int) $data['statement_line_id'],
                (int) Auth::id(),
                isset($data['match_id']) ? (int) $data['match_id'] : null
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $removed > 0 ? __('Treasury match removed.') : __('Nothing to remove.'));
    }

    // ------------------------------------------------------------------
    // API sync
    // ------------------------------------------------------------------

    public function apiSyncForm()
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.reconciliation.api-sync-form', [
            'accounts' => BankAccount::where('created_by', $creatorId)->orderBy('bank_name')->get(),
        ]);
    }

    /**
     * Import statement rows from a provider export (JSON file).
     * Real provider connectors land in Phase 10; this endpoint is
     * the seam they will call — same rows, same cursor.
     */
    public function apiSync(Request $request)
    {
        if (! Auth::user()->can(TreasuryReconciliationService::PERMISSION_MANAGE)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'source_key' => 'required|string|max:64',
            'rows_file' => 'required|file|mimes:json,txt|max:10240',
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        $bank = BankAccount::where('id', $data['bank_account_id'])->where('created_by', $creatorId)->first();
        if ($bank === null) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $decoded = json_decode((string) file_get_contents($request->file('rows_file')->getRealPath()), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            return redirect()->back()->with('error', __('The file must be JSON with a "rows" list. Each row needs external_id, txn_date and amount.'));
        }

        try {
            $result = $this->service->importFromApi(
                $creatorId,
                $bank->id,
                $data['source_key'],
                $decoded['rows'],
                $decoded['next_cursor'] ?? null
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('bank-reconciliation.show', $result['session'])
            ->with('success', __('API sync imported :imported new line(s), skipped :skipped already-seen line(s).', ['imported' => $result['imported'], 'skipped' => $result['skipped']]));
    }

    protected function authorizeSession(BankReconciliationSession $session): void
    {
        if ((int) $session->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }
    }

    protected function authorizeMovement(TreasuryMissingMovement $movement): void
    {
        if ((int) $movement->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }
    }
}
