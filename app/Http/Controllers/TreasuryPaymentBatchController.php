<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryPaymentBatch;
use App\Models\TreasuryPaymentRequest;
use App\Services\TreasuryPaymentBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 3 — payment batches (plan §5, §6, CA07).
 *
 * All rules live in TreasuryPaymentBatchService; this controller
 * only gates permissions, scopes to the company and turns service
 * exceptions into redirects.
 */
class TreasuryPaymentBatchController extends Controller
{
    protected TreasuryPaymentBatchService $service;

    public function __construct(?TreasuryPaymentBatchService $service = null)
    {
        $this->service = $service ?? new TreasuryPaymentBatchService();
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryPaymentBatch::where('created_by', $creatorId)
            ->withCount('items')
            ->orderByDesc('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }

        $batches = $query->paginate(25);

        return view('treasury.payment-batches.index', compact('batches'));
    }

    public function create()
    {
        if (! Auth::user()->can('execute treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $requests = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->whereIn('state', [
                TreasuryPaymentRequest::STATE_APPROVED,
                TreasuryPaymentRequest::STATE_PROCESSING,
            ])
            ->with('operation')
            ->orderByDesc('id')
            ->get()
            ->filter(function (TreasuryPaymentRequest $request) use ($creatorId) {
                return ! \App\Models\TreasuryPaymentBatchItem::where('payment_request_id', $request->id)
                    ->whereHas('batch', fn ($q) => $q
                        ->where('created_by', $creatorId)
                        ->whereIn('state', TreasuryPaymentBatch::OPEN_STATES))
                    ->exists();
            })
            ->values();

        return view('treasury.payment-batches.form', compact('requests'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('execute treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'provider' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:1000',
            'payment_request_id' => 'required|array|min:1',
            'payment_request_id.*' => 'integer',
        ]);

        $items = [];
        foreach ((array) $request->input('payment_request_id', []) as $index => $requestId) {
            $amount = $request->input('amount_original.' . $index);
            $items[] = [
                'payment_request_id' => (int) $requestId,
                'amount_original' => $amount !== null && $amount !== '' ? (string) $amount : null,
            ];
        }

        try {
            $batch = $this->service->createBatch(
                (int) Auth::user()->creatorId(),
                [
                    'provider' => $request->input('provider'),
                    'note' => $request->input('note'),
                    'items' => $items,
                ],
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-batches.show', $batch)
            ->with('success', __('Payment batch created as a draft. Execute it to settle its items.'));
    }

    public function show(TreasuryPaymentBatch $batch)
    {
        if (! Auth::user()->can('view treasury')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $batch = $this->service->batchForCompany((int) Auth::user()->creatorId(), (int) $batch->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $batch->load(['items.paymentRequest.operation', 'items.attempt']);

        return view('treasury.payment-batches.show', compact('batch'));
    }

    public function execute(TreasuryPaymentBatch $batch)
    {
        if (! Auth::user()->can('execute treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $batch = $this->service->executeBatch(
                (int) Auth::user()->creatorId(),
                (int) $batch->id,
                (int) Auth::user()->id
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $paid = $batch->items()->where('state', 'paid')->count();
        $failed = $batch->items()->where('state', 'failed')->count();

        if ($batch->isPaid()) {
            $message = __('Batch executed — all :count items settled.', ['count' => $paid]);
        } elseif ($batch->isPartiallyPaid()) {
            $message = __('Batch partially executed — :paid items settled, :failed items failed and kept for review.', ['paid' => $paid, 'failed' => $failed]);
        } else {
            $message = __('Batch execution finished with no settled items — :failed items failed and kept for review.', ['failed' => $failed]);
        }

        return redirect()->route('treasury-payment-batches.show', $batch)->with('success', $message);
    }

    public function cancel(TreasuryPaymentBatch $batch)
    {
        if (! Auth::user()->can('execute treasury payments')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $batch = $this->service->cancelBatch($batch, (int) Auth::user()->id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-payment-batches.show', $batch)
            ->with('success', __('Payment batch cancelled.'));
    }
}
