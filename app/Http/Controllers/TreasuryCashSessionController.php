<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\PosShift;
use App\Models\TreasuryCashSession;
use App\Models\TreasuryFinancialAccount;
use App\Models\User;
use App\Services\TreasuryCashSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 5 — cash register sessions (plan §5, §6).
 *
 * All rules live in TreasuryCashSessionService; this controller only
 * gates permissions, scopes to the company and turns service
 * exceptions into redirects.
 */
class TreasuryCashSessionController extends Controller
{
    protected TreasuryCashSessionService $service;

    public function __construct(?TreasuryCashSessionService $service = null)
    {
        $this->service = $service ?? new TreasuryCashSessionService();
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryCashSession::where('created_by', $creatorId)
            ->with(['register', 'operator'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('register_id')) {
            $query->where('register_id', (int) $request->register_id);
        }

        $sessions = $query->paginate(25);
        $registers = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('type', TreasuryFinancialAccount::TYPE_CASH_REGISTER)
            ->orderBy('code')
            ->get();

        return view('treasury.cash-sessions.index', compact('sessions', 'registers'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.cash-sessions.form', [
            'registers' => $this->registerAccounts($creatorId),
            'operators' => $this->companyUsers($creatorId),
            'shifts' => $this->feedableShifts($creatorId),
            'baseCurrency' => \App\Services\TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'register_id' => 'required|integer',
            'operator_user_id' => 'required|integer',
            'opening_float' => 'required|numeric|min:0',
            'branch_id' => 'nullable|integer',
            'shift_id' => 'nullable|integer',
            'exchange_rate' => 'nullable|numeric|min:0',
            'rate_source' => 'nullable|string|max:20',
            'rate_date' => 'nullable|date',
        ]);

        try {
            $session = $this->service->openSession(
                (int) Auth::user()->creatorId(),
                $request->only([
                    'register_id', 'operator_user_id', 'opening_float', 'branch_id',
                    'shift_id', 'exchange_rate', 'rate_source', 'rate_date',
                ]),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-sessions.show', $session)
            ->with('success', __('Cash session opened. Record movements, count the cash, then close it.'));
    }

    public function show(TreasuryCashSession $session)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $session = $this->service->sessionForCompany((int) Auth::user()->creatorId(), (int) $session->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $session->load(['register', 'operator', 'shift', 'counts', 'movements.transfer', 'operation', 'varianceOperation']);
        $accounts = $this->handoverAccounts((int) Auth::user()->creatorId(), (int) $session->register_id);

        return view('treasury.cash-sessions.show', compact('session', 'accounts'));
    }

    public function recordMovement(Request $request, TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'type' => 'required|in:inflow,outflow,float_top_up,withdrawal',
            'method' => 'nullable|in:cash,card,mobile',
            'amount' => 'required|numeric|min:0.0001',
            'reference' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $this->service->recordMovement(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                $request->only(['type', 'method', 'amount', 'reference', 'reason']),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-sessions.show', $session)
            ->with('success', __('Movement recorded.'));
    }

    public function countForm(TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $session = $this->service->sessionForCompany((int) Auth::user()->creatorId(), (int) $session->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $session->load(['register', 'counts']);

        return view('treasury.cash-sessions.count', compact('session'));
    }

    public function recordCount(Request $request, TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'counts' => 'required|array|min:1',
            'counts.*.denomination' => 'required|numeric|min:0.0001',
            'counts.*.count' => 'required|integer|min:0',
        ]);

        try {
            $session = $this->service->recordCount(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                $request->input('counts'),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-sessions.show', $session)
            ->with('success', __('Count recorded — counted :counted against expected :expected, variance :variance :currency.', [
                'counted' => number_format((float) $session->counted_balance, 2),
                'expected' => number_format((float) $session->expected_balance, 2),
                'variance' => number_format((float) $session->variance_amount, 2),
                'currency' => $session->currency,
            ]));
    }

    public function closeForm(TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $preview = $this->service->previewClose(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $approvers = $this->varianceApprovers((int) Auth::user()->creatorId(), (int) Auth::user()->id);

        return view('treasury.cash-sessions.close', compact('preview', 'approvers'));
    }

    public function close(Request $request, TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'variance_reason' => 'nullable|string|max:1000',
            'variance_approved_by' => 'nullable|integer',
            'posting_date' => 'nullable|date',
            'adjustment.authorised_by' => 'nullable|integer',
            'adjustment.reason' => 'nullable|string|max:1000',
        ]);

        $options = [];
        if ($request->filled('variance_reason')) {
            $options['variance_reason'] = $request->input('variance_reason');
        }
        if ($request->filled('variance_approved_by')) {
            $options['variance_approved_by'] = (int) $request->input('variance_approved_by');
        }
        if ($request->filled('posting_date')) {
            $options['posting_date'] = $request->input('posting_date');
        }
        if ($request->filled('adjustment.authorised_by') || $request->filled('adjustment.reason')) {
            $options['adjustment'] = [
                'authorised_by' => (int) $request->input('adjustment.authorised_by'),
                'reason' => (string) $request->input('adjustment.reason'),
            ];
        }

        try {
            $session = $this->service->closeSession(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                (int) Auth::user()->id,
                $options
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $message = bccomp((string) $session->variance_amount, '0', 4) === 0
            ? __('Session closed — the count matches the expected balance, no variance.')
            : __('Session closed — variance :variance :currency posted as a separate adjustment.', [
                'variance' => number_format((float) $session->variance_amount, 2),
                'currency' => $session->currency,
            ]);

        return redirect()->route('treasury-cash-sessions.show', $session)->with('success', $message);
    }

    public function reopen(Request $request, TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        try {
            $session = $this->service->reopenSession(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                (int) Auth::user()->id,
                (string) $request->input('reason')
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-sessions.show', $session)
            ->with('success', __('Session reopened. The count and the variance already posted stay as they are — a re-close posts only the delta.'));
    }

    public function lock(Request $request, TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        try {
            $session = $this->service->lockSession(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                (int) Auth::user()->id,
                (string) $request->input('reason')
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-sessions.show', $session)
            ->with('success', __('Session locked. Locked sessions cannot be reopened.'));
    }

    public function handoverForm(TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $session = $this->service->sessionForCompany((int) Auth::user()->creatorId(), (int) $session->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $accounts = $this->handoverAccounts((int) Auth::user()->creatorId(), (int) $session->register_id);

        return view('treasury.cash-sessions.handover', compact('session', 'accounts'));
    }

    public function handover(Request $request, TreasuryCashSession $session)
    {
        if (! Auth::user()->can('manage treasury cash sessions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'destination_account_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.0001',
            'reference' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:1000',
            'posting_date' => 'nullable|date',
        ]);

        try {
            $movement = $this->service->handover(
                (int) Auth::user()->creatorId(),
                (int) $session->id,
                $request->only(['destination_account_id', 'amount', 'reference', 'reason', 'posting_date']),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-sessions.show', $session)
            ->with('success', __('Handover complete — :amount :currency moved to the central account (transfer :reference).', [
                'amount' => number_format((float) $movement->amount, 2),
                'currency' => $session->currency,
                'reference' => $movement->reference ?? '',
            ]));
    }

    /** Active cash-register accounts a session can open on. */
    protected function registerAccounts(int $creatorId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->where('type', TreasuryFinancialAccount::TYPE_CASH_REGISTER)
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }

    /** Active accounts a handover can move cash to (never the register itself, never transit). */
    protected function handoverAccounts(int $creatorId, int $registerId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->where('id', '!=', $registerId)
            ->where('type', '!=', TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT)
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }

    /** Company users who can operate a session (company + staff). */
    protected function companyUsers(int $creatorId)
    {
        return User::where(function ($query) use ($creatorId) {
            $query->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get();
    }

    /** Closed POS shifts that have not fed a session yet. */
    protected function feedableShifts(int $creatorId)
    {
        $fed = TreasuryCashSession::where('created_by', $creatorId)
            ->whereNotNull('shift_id')
            ->pluck('shift_id')
            ->all();

        return PosShift::where('created_by', $creatorId)
            ->where('status', PosShift::STATUS_CLOSED)
            ->whereNotIn('id', $fed)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /** Users who may approve a variance (not the current user — approval is always separate). */
    protected function varianceApprovers(int $creatorId, int $excludeUserId)
    {
        return User::where(function ($query) use ($creatorId) {
            $query->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->where('id', '!=', $excludeUserId)->orderBy('name')->get();
    }
}
