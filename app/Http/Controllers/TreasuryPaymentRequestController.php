<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\TreasuryApprovalDecision;
use App\Models\TreasuryApprovalStep;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryFundReservation;
use App\Models\TreasuryPaymentRequest;
use App\Models\Vender;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — payment requests + approvals + reservations
 * (plan §4, §5, §6).
 *
 * All rules live in TreasuryPaymentService; this controller only
 * gates permissions, scopes to the company and turns service
 * exceptions into redirects. Execution itself is Phase 3 — this
 * phase builds the request/approval/reservation layer only.
 */
class TreasuryPaymentRequestController extends Controller
{
    protected TreasuryPaymentService $service;
    protected TreasuryAccountService $accounts;

    public function __construct(TreasuryPaymentService $service, ?TreasuryAccountService $accounts = null)
    {
        $this->service = $service;
        $this->accounts = $accounts ?? new TreasuryAccountService();
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->with(['operation', 'sourceAccount'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('beneficiary_type')) {
            $query->where('beneficiary_type', $request->beneficiary_type);
        }
        if ($request->filled('beneficiary_id')) {
            $query->where('beneficiary_id', (int) $request->beneficiary_id);
        }

        $requests = $query->paginate(25);

        return view('treasury.payment-requests.index', compact('requests'));
    }

    /**
     * Approvals queue: pending steps on requests awaiting approval
     * where the current user is an eligible approver (or the assigned
     * substitute). The decision data, history and the request itself
     * are all on the request page.
     */
    public function approvals()
    {
        if (! Auth::user()->can('approve treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $userId = (int) Auth::user()->id;

        $steps = TreasuryApprovalStep::where('status', TreasuryApprovalStep::STATUS_PENDING)
            ->whereHas('request', fn ($q) => $q
                ->where('created_by', $creatorId)
                ->where('state', TreasuryPaymentRequest::STATE_AWAITING_APPROVAL))
            ->with(['request.operation', 'request.sourceAccount'])
            ->orderBy('id')
            ->get()
            ->filter(function (TreasuryApprovalStep $step) use ($userId) {
                if (in_array($userId, $step->eligibleUserIds(), true)) {
                    return true;
                }

                return (int) $step->substitute_user_id === $userId && $step->substituteIsValid();
            })
            ->values();

        return view('treasury.payment-requests.approvals', compact('steps'));
    }

    /** Fund reservations for the company (active first). */
    public function reservations(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryFundReservation::where('created_by', $creatorId)
            ->with(['request.operation', 'account'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        $reservations = $query->paginate(25);

        return view('treasury.payment-requests.reservations', compact('reservations'));
    }

    public function create()
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('treasury.payment-requests.form', array_merge(
            ['paymentRequest' => null],
            $this->formData()
        ));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'source_account_id' => 'required|integer',
            'beneficiary_type' => 'required|string',
            'amount_original' => 'required|numeric|min:0.0001',
            'currency' => 'nullable|string|size:3',
            'due_date' => 'nullable|date',
            'planned_date' => 'nullable|date',
        ]);

        try {
            $paymentRequest = $this->service->createRequest(
                (int) Auth::user()->creatorId(),
                $this->requestData($request),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)
            ->with('success', __('Payment request created as a draft. Submit it to start the approval workflow.'));
    }

    public function show(TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        $paymentRequest->load(['operation', 'sourceAccount', 'steps.decisions', 'reservations', 'attempts']);

        $availableBalance = null;
        if ($paymentRequest->sourceAccount) {
            $availableBalance = $this->accounts->availableBalance($paymentRequest->sourceAccount);
        }

        $billOutstanding = [];
        foreach ($paymentRequest->sourceDocuments() as $doc) {
            if ($doc['type'] === 'bill') {
                $billOutstanding[(int) $doc['id']] = $this->service->billOutstanding((int) Auth::user()->creatorId(), (int) $doc['id']);
            }
        }

        $users = \App\Models\User::where(function ($q) {
            $creatorId = (int) Auth::user()->creatorId();
            $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get(['id', 'name']);

        return view('treasury.payment-requests.show', compact('paymentRequest', 'availableBalance', 'billOutstanding', 'users'));
    }

    public function edit(TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        if (! in_array($paymentRequest->state, [
            TreasuryPaymentRequest::STATE_DRAFT,
            TreasuryPaymentRequest::STATE_UNDER_REVIEW,
            TreasuryPaymentRequest::STATE_AWAITING_APPROVAL,
            TreasuryPaymentRequest::STATE_APPROVED,
        ], true)) {
            return redirect()->route('treasury-payment-requests.show', $paymentRequest)
                ->with('error', __('This payment request can no longer be edited.'));
        }

        return view('treasury.payment-requests.form', array_merge(
            ['paymentRequest' => $paymentRequest->load('operation')],
            $this->formData()
        ));
    }

    public function update(Request $request, TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        $request->validate([
            'source_account_id' => 'required|integer',
            'beneficiary_type' => 'required|string',
            'amount_original' => 'required|numeric|min:0.0001',
            'currency' => 'nullable|string|size:3',
            'due_date' => 'nullable|date',
            'planned_date' => 'nullable|date',
        ]);

        try {
            $paymentRequest = $this->service->updateRequest($paymentRequest, $this->requestData($request), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $notice = __('Payment request updated.');
        if ($paymentRequest->isUnderReview() && $paymentRequest->approved_snapshot === null && $paymentRequest->steps()->where('status', TreasuryApprovalStep::STATUS_INVALIDATED)->exists()) {
            $notice = __('Payment request updated. The protected details changed, so the approvals were invalidated and the workflow restarted.');
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)->with('success', $notice);
    }

    public function destroy(TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        try {
            $this->service->deleteRequest($paymentRequest);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.index')
            ->with('success', __('Draft payment request deleted.'));
    }

    public function submit(TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        try {
            $paymentRequest = $this->service->submitRequest($paymentRequest, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)
            ->with('success', __('Payment request submitted. Reference :ref issued.', ['ref' => $paymentRequest->operation->reference]));
    }

    public function sendForApproval(TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        try {
            $paymentRequest = $this->service->sendForApproval($paymentRequest, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)
            ->with('success', __('Payment request sent for approval.'));
    }

    public function reject(Request $request, TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        try {
            $paymentRequest = $this->service->rejectRequest($paymentRequest, (int) Auth::user()->id, $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)
            ->with('success', __('Payment request rejected.'));
    }

    public function returnToDraft(Request $request, TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        try {
            $paymentRequest = $this->service->returnToDraft($paymentRequest, (int) Auth::user()->id, $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)
            ->with('success', __('Payment request returned to draft.'));
    }

    public function cancel(Request $request, TreasuryPaymentRequest $paymentRequest)
    {
        if (! Auth::user()->can('create treasury payment requests')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$paymentRequest, $redirect] = $this->scopedRequestOrRedirect($paymentRequest);
        if ($redirect) {
            return $redirect;
        }

        try {
            $paymentRequest = $this->service->cancelRequest($paymentRequest, (int) Auth::user()->id, $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $paymentRequest)
            ->with('success', __('Payment request cancelled.'));
    }

    public function decide(Request $request, TreasuryApprovalStep $step)
    {
        if (! Auth::user()->can('approve treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'decision' => 'required|string|in:approve,reject,return',
        ]);

        [$step, $redirect] = $this->scopedStepOrRedirect($step);
        if ($redirect) {
            return $redirect;
        }

        try {
            $step = $this->service->decide($step, (int) Auth::user()->id, (string) $request->input('decision'), $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $decision = (string) $request->input('decision');
        $message = $decision === TreasuryApprovalDecision::DECISION_APPROVE
            ? __('Approval recorded.')
            : ($decision === TreasuryApprovalDecision::DECISION_REJECT
                ? __('Payment request rejected.')
                : __('Payment request returned to draft.'));

        return redirect()->route('treasury-payment-requests.show', $step->payment_request_id)
            ->with('success', $message);
    }

    public function assignSubstitute(Request $request, TreasuryApprovalStep $step)
    {
        if (! Auth::user()->can('approve treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'substitute_user_id' => 'required|integer',
            'substitute_expiry' => 'nullable|date',
        ]);

        [$step, $redirect] = $this->scopedStepOrRedirect($step);
        if ($redirect) {
            return $redirect;
        }

        try {
            $this->service->assignSubstitute($step, (int) $request->input('substitute_user_id'), $request->input('substitute_expiry'), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-requests.show', $step->payment_request_id)
            ->with('success', __('Substitute approver assigned.'));
    }

    // ------------------------------------------------------------------

    /**
     * Normalise the form input for the service. The form sends source
     * documents as "bill:1" checkbox values; the service expects
     * [['type' => 'bill', 'id' => 1], ...].
     */
    protected function requestData(Request $request): array
    {
        $data = $request->all();

        $documents = [];
        foreach ((array) ($data['source_documents'] ?? []) as $doc) {
            if (is_array($doc) && isset($doc['type'], $doc['id'])) {
                $documents[] = ['type' => (string) $doc['type'], 'id' => (int) $doc['id']];
            } elseif (is_string($doc) && preg_match('/^(bill|invoice):(\d+)$/', trim($doc), $m)) {
                $documents[] = ['type' => $m[1], 'id' => (int) $m[2]];
            }
        }
        $data['source_documents'] = $documents;

        return $data;
    }

    protected function scopedRequest(TreasuryPaymentRequest $paymentRequest): TreasuryPaymentRequest
    {
        return $this->service->requestForCompany((int) Auth::user()->creatorId(), (int) $paymentRequest->id);
    }

    /**
     * Scope a request to the company. When it belongs to another
     * company the service throws — turn that into a redirect with
     * an error instead of a 500.
     *
     * @return array{0: TreasuryPaymentRequest|null, 1: \Illuminate\Http\RedirectResponse|null}
     */
    protected function scopedRequestOrRedirect(TreasuryPaymentRequest $paymentRequest): array
    {
        try {
            return [$this->scopedRequest($paymentRequest), null];
        } catch (TreasuryException $e) {
            return [null, redirect()->back()->with('error', $e->getMessage())];
        }
    }

    protected function scopedStep(TreasuryApprovalStep $step): TreasuryApprovalStep
    {
        return $this->service->stepForCompany((int) Auth::user()->creatorId(), (int) $step->id);
    }

    /**
     * @return array{0: TreasuryApprovalStep|null, 1: \Illuminate\Http\RedirectResponse|null}
     */
    protected function scopedStepOrRedirect(TreasuryApprovalStep $step): array
    {
        try {
            return [$this->scopedStep($step), null];
        } catch (TreasuryException $e) {
            return [null, redirect()->back()->with('error', $e->getMessage())];
        }
    }

    protected function formData(): array
    {
        $creatorId = (int) Auth::user()->creatorId();

        $vendors = Schema::hasTable('venders')
            ? Vender::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name'])
            : collect();
        $employees = Schema::hasTable('employees')
            ? Employee::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name'])
            : collect();
        $customers = Schema::hasTable('customers')
            ? Customer::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name'])
            : collect();
        $bills = Schema::hasTable('bills')
            ? Bill::where('created_by', $creatorId)->whereNull('voided_at')->orderByDesc('id')->limit(200)->get(['id', 'bill_id', 'bill_date', 'due_date'])
            : collect();
        $costCentres = Schema::hasTable('cost_centres')
            ? \App\Models\CostCentre::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name'])
            : collect();
        $users = \App\Models\User::where(function ($q) use ($creatorId) {
            $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get(['id', 'name']);

        return [
            'accounts' => TreasuryFinancialAccount::where('created_by', $creatorId)
                ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
                ->with('bankAccount')
                ->orderBy('code')
                ->get(),
            'vendors' => $vendors,
            'employees' => $employees,
            'customers' => $customers,
            'bills' => $bills,
            'costCentres' => $costCentres,
            'users' => $users,
            'currencies' => TreasuryAccountController::CURRENCIES,
        ];
    }
}
