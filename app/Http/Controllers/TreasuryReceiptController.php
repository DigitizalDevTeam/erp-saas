<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryReceipt;
use App\Services\TreasuryReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 1 — receipts + allocations (plan §3, §5).
 *
 * All rules live in TreasuryReceiptService; this controller only
 * gates permissions, scopes to the company and turns service
 * exceptions into redirects.
 */
class TreasuryReceiptController extends Controller
{
    protected TreasuryReceiptService $service;

    public function __construct(TreasuryReceiptService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryReceipt::where('created_by', $creatorId)
            ->with(['operation', 'customer'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }

        $receipts = $query->paginate(25);
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        return view('treasury.receipts.index', compact('receipts', 'customers'));
    }

    public function create()
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('treasury.receipts.form', array_merge(
            ['receipt' => null],
            $this->formData()
        ));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'financial_account_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.0001',
            'method' => 'required|string',
            'posting_date' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'value_date' => 'nullable|date',
        ]);

        try {
            $receipt = $this->service->createReceipt(
                (int) Auth::user()->creatorId(),
                $request->all(),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Receipt created as a draft. Submit it to start confirmation.'));
    }

    public function show(TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }
        $receipt->load(['operation', 'customer', 'allocations.invoice']);

        $creatorId = (int) Auth::user()->creatorId();
        $remaining = $receipt->isConfirmed() ? $this->service->remainingUnapplied($receipt) : '0.0000';
        $remainingBase = $receipt->isConfirmed() ? $this->service->remainingUnappliedBase($receipt) : '0.0000';
        $unappliedCredit = $receipt->customer_id
            ? $this->service->unappliedCredit($creatorId, (int) $receipt->customer_id, (string) $receipt->operation->currency)
            : '0.0000';

        $openInvoices = collect();
        if ($receipt->isConfirmed() && $receipt->customer_id) {
            $openInvoices = Invoice::where('created_by', $creatorId)
                ->where('customer_id', $receipt->customer_id)
                ->whereNotIn('status', [Invoice::STATUS_CANCELLED])
                ->orderBy('issue_date')
                ->get()
                ->filter(fn (Invoice $invoice) => bccomp($this->service->invoiceDue($invoice), '0', 4) > 0)
                ->values();
        }

        $users = \App\Models\User::where(function ($q) use ($creatorId) {
            $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->orderBy('name')->get(['id', 'name']);

        return view('treasury.receipts.show', compact('receipt', 'remaining', 'remainingBase', 'unappliedCredit', 'openInvoices', 'users'));
    }

    public function edit(TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        if (! $receipt->isDraft()) {
            return redirect()->route('treasury-receipts.show', $receipt)
                ->with('error', __('Only a draft receipt can be edited.'));
        }

        return view('treasury.receipts.form', array_merge(
            ['receipt' => $receipt->load('operation')],
            $this->formData()
        ));
    }

    public function update(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $receipt = $this->service->updateReceipt($receipt, $request->all(), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Receipt updated.'));
    }

    public function destroy(TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $this->service->deleteReceipt($receipt, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.index')
            ->with('success', __('Draft receipt deleted.'));
    }

    public function submit(TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('confirm treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $receipt = $this->service->submitReceipt($receipt, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Receipt submitted. Reference :ref issued.', ['ref' => $receipt->operation->reference]));
    }

    public function confirm(TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('confirm treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $receipt = $this->service->confirmReceipt($receipt, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Receipt confirmed. Receipt number :number issued.', ['number' => $receipt->receipt_number]));
    }

    public function reject(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('confirm treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $receipt = $this->service->rejectReceipt($receipt, (string) $request->input('reason', ''), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Receipt rejected.'));
    }

    public function cancel(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $receipt = $this->service->cancelReceipt($receipt, $request->input('reason'), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Receipt cancelled.'));
    }

    public function reverse(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('reverse treasury operations')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $reversal = $this->service->reverseReceipt($receipt, (string) $request->input('reason', ''), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $reversal)
            ->with('success', __('Receipt reversed. Reversal receipt :number created.', ['number' => $reversal->receipt_number]));
    }

    public function attachEvidence(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('create treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        try {
            $receipt = $this->service->attachEvidence($receipt, (string) $request->input('evidence', ''), (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Evidence attached. It changes nothing until the receipt is confirmed.'));
    }

    public function allocate(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('allocate treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }

        $rows = [];
        foreach ((array) $request->input('allocations', []) as $row) {
            if (! is_array($row) || empty($row['invoice_id']) || empty($row['amount'])) {
                continue;
            }
            $rows[] = [
                'invoice_id' => (int) $row['invoice_id'],
                'amount' => (string) $row['amount'],
                'exchange_rate' => $row['exchange_rate'] ?? null,
                'rate_source' => $row['rate_source'] ?? null,
                'rate_date' => $row['rate_date'] ?? null,
            ];
        }

        if ($rows === []) {
            return redirect()->back()->with('error', __('Add at least one allocation with an amount.'));
        }

        $approvedBy = $request->filled('approved_by') ? (int) $request->input('approved_by') : null;

        try {
            $this->service->allocate($receipt, $rows, (int) Auth::user()->id, $approvedBy);
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Allocations posted. The invoices are settled by the allocated amounts.'));
    }

    public function applyCredit(Request $request, TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('allocate treasury receipts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }
        $creatorId = (int) Auth::user()->creatorId();

        $request->validate([
            'invoice_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.0001',
        ]);

        try {
            $this->service->applyUnappliedCredit(
                $creatorId,
                (int) $receipt->customer_id,
                (int) $request->input('invoice_id'),
                (string) $request->input('amount'),
                (string) $receipt->operation->currency,
                (int) Auth::user()->id,
                $request->filled('approved_by') ? (int) $request->input('approved_by') : null
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-receipts.show', $receipt)
            ->with('success', __('Unapplied credit applied to the invoice. No new cash was recorded.'));
    }

    public function pdf(TreasuryReceipt $receipt)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        [$receipt, $redirect] = $this->scopedReceiptOrRedirect($receipt);
        if ($redirect) {
            return $redirect;
        }
        $receipt->load(['operation', 'customer', 'allocations.invoice']);

        return Pdf::loadView('treasury.receipts.pdf', compact('receipt'))
            ->download('receipt-' . ($receipt->receipt_number ?? $receipt->id) . '.pdf');
    }

    // ------------------------------------------------------------------

    protected function scopedReceipt(TreasuryReceipt $receipt): TreasuryReceipt
    {
        return $this->service->receiptForCompany((int) Auth::user()->creatorId(), (int) $receipt->id);
    }

    /**
     * Scope a receipt to the company. When it belongs to another
     * company the service throws — turn that into a redirect with
     * an error instead of a 500.
     *
     * @return array{0: TreasuryReceipt|null, 1: \Illuminate\Http\RedirectResponse|null}
     */
    protected function scopedReceiptOrRedirect(TreasuryReceipt $receipt): array
    {
        try {
            return [$this->scopedReceipt($receipt), null];
        } catch (TreasuryException $e) {
            return [null, redirect()->back()->with('error', $e->getMessage())];
        }
    }

    protected function formData(): array
    {
        $creatorId = (int) Auth::user()->creatorId();

        return [
            'accounts' => TreasuryFinancialAccount::where('created_by', $creatorId)
                ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
                ->with('bankAccount')
                ->orderBy('code')
                ->get(),
            'customers' => Customer::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'methods' => TreasuryReceipt::METHOD_LABELS,
            'currencies' => TreasuryAccountController::CURRENCIES,
        ];
    }
}
