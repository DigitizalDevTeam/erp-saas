<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryTransfer;
use App\Services\TreasuryTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 4 — internal transfers with FX (plan §5, §6).
 *
 * All rules live in TreasuryTransferService; this controller only
 * gates permissions, scopes to the company and turns service
 * exceptions into redirects.
 */
class TreasuryTransferController extends Controller
{
    protected TreasuryTransferService $service;

    public function __construct(?TreasuryTransferService $service = null)
    {
        $this->service = $service ?? new TreasuryTransferService();
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryTransfer::where('created_by', $creatorId)
            ->with(['operation', 'sourceAccount', 'destinationAccount', 'transitAccount'])
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        $transfers = $query->paginate(25);

        return view('treasury.transfers.index', compact('transfers'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.transfers.form', [
            'transfer' => null,
            'accounts' => $this->transferAccounts($creatorId),
            'transitAccounts' => $this->transitAccounts($creatorId),
            'baseCurrency' => \App\Services\TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate($this->formRules());

        try {
            $transfer = $this->service->createTransfer(
                (int) Auth::user()->creatorId(),
                $request->only([
                    'source_account_id', 'destination_account_id', 'transit_account_id',
                    'timing', 'amount_debited', 'exchange_rate', 'rate_source', 'rate_date',
                    'base_exchange_rate', 'fee_amount', 'evidence', 'branch_id', 'transfer_date',
                ]),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-transfers.show', $transfer)
            ->with('success', __('Transfer created as a draft. Confirm it to move the money.'));
    }

    public function show(TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $transfer = $this->service->transferForCompany((int) Auth::user()->creatorId(), (int) $transfer->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $transfer->load(['operation', 'sourceAccount', 'destinationAccount', 'transitAccount']);

        return view('treasury.transfers.show', compact('transfer'));
    }

    public function edit(TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $transfer = $this->service->transferForCompany((int) Auth::user()->creatorId(), (int) $transfer->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if (! $transfer->isDraft()) {
            return redirect()->route('treasury-transfers.show', $transfer)
                ->with('error', __('Only a draft transfer can be edited.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.transfers.form', [
            'transfer' => $transfer,
            'accounts' => $this->transferAccounts($creatorId),
            'transitAccounts' => $this->transitAccounts($creatorId),
            'baseCurrency' => \App\Services\TreasurySettings::baseCurrency($creatorId),
        ]);
    }

    public function update(Request $request, TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate($this->formRules());

        try {
            $transfer = $this->service->transferForCompany((int) Auth::user()->creatorId(), (int) $transfer->id);
            $transfer = $this->service->updateTransfer(
                $transfer,
                $request->only([
                    'source_account_id', 'destination_account_id', 'transit_account_id',
                    'timing', 'amount_debited', 'exchange_rate', 'rate_source', 'rate_date',
                    'base_exchange_rate', 'fee_amount', 'evidence', 'branch_id', 'transfer_date',
                ]),
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-transfers.show', $transfer)
            ->with('success', __('Transfer draft updated.'));
    }

    public function confirmForm(TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $preview = $this->service->previewConfirm(
                (int) Auth::user()->creatorId(),
                (int) $transfer->id,
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return view('treasury.transfers.confirm', compact('preview'));
    }

    public function confirm(Request $request, TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'posting_date' => 'nullable|date',
            'adjustment.authorised_by' => 'nullable|integer',
            'adjustment.reason' => 'nullable|string|max:1000',
        ]);

        $options = [];
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
            $result = $this->service->confirmTransfer(
                (int) Auth::user()->creatorId(),
                (int) $transfer->id,
                (int) Auth::user()->id,
                $options
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $message = $result['transfer']->isInTransit()
            ? __('Transfer confirmed — :amount :currency is in transit. Clear it when the money arrives at the destination.', [
                'amount' => $result['amounts']['amount_debited'],
                'currency' => $result['transfer']->source_currency,
            ])
            : __('Transfer confirmed — :amount :currency moved to the destination.', [
                'amount' => $result['amounts']['amount_debited'],
                'currency' => $result['transfer']->source_currency,
            ]);

        return redirect()->route('treasury-transfers.show', $result['transfer'])->with('success', $message);
    }

    public function clear(Request $request, TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'posting_date' => 'nullable|date',
            'adjustment.authorised_by' => 'nullable|integer',
            'adjustment.reason' => 'nullable|string|max:1000',
        ]);

        $options = [];
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
            $result = $this->service->clearTransfer(
                (int) Auth::user()->creatorId(),
                (int) $transfer->id,
                (int) Auth::user()->id,
                $options
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-transfers.show', $result['transfer'])
            ->with('success', __('Transfer cleared — the money arrived at the destination and the transit balance is zero.'));
    }

    public function return(Request $request, TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'reason' => 'nullable|string|max:1000',
            'posting_date' => 'nullable|date',
            'adjustment.authorised_by' => 'nullable|integer',
            'adjustment.reason' => 'nullable|string|max:1000',
        ]);

        $options = [];
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
            $result = $this->service->returnTransfer(
                (int) Auth::user()->creatorId(),
                (int) $transfer->id,
                (int) Auth::user()->id,
                $request->input('reason'),
                $options
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-transfers.show', $result['transfer'])
            ->with('success', __('Transfer returned — the money is back in the source account. The transfer fee stays charged.'));
    }

    public function cancel(TreasuryTransfer $transfer)
    {
        if (! Auth::user()->can('manage treasury transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $transfer = $this->service->transferForCompany((int) Auth::user()->creatorId(), (int) $transfer->id);
            $transfer = $this->service->cancelTransfer($transfer, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-transfers.show', $transfer)
            ->with('success', __('Transfer draft cancelled.'));
    }

    protected function formRules(): array
    {
        return [
            'source_account_id' => 'required|integer',
            'destination_account_id' => 'required|integer',
            'transit_account_id' => 'nullable|integer',
            'timing' => 'nullable|in:immediate,delayed',
            'amount_debited' => 'required|numeric|min:0.0001',
            'exchange_rate' => 'nullable|numeric|min:0',
            'rate_source' => 'nullable|string|max:20',
            'rate_date' => 'nullable|date',
            'base_exchange_rate' => 'nullable|numeric|min:0',
            'fee_amount' => 'nullable|numeric|min:0',
            'evidence' => 'nullable|string|max:1000',
            'branch_id' => 'nullable|integer',
            'transfer_date' => 'nullable|date',
        ];
    }

    /** Active accounts a transfer can move between (never a transit account). */
    protected function transferAccounts(int $creatorId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->where('type', '!=', TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT)
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }

    /** Active funds-in-transit accounts for delayed transfers. */
    protected function transitAccounts(int $creatorId)
    {
        return TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->where('type', TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT)
            ->with('bankAccount')
            ->orderBy('code')
            ->get();
    }
}
