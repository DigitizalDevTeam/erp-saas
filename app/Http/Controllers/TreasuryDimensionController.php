<?php

namespace App\Http\Controllers;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CostCentre;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\TreasuryLineDimension;
use App\Services\CostCentreService;
use App\Services\TreasuryDimensionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TreasuryDimensionController extends Controller
{
    public function __construct(
        protected TreasuryDimensionService $service,
        protected CostCentreService $centres = new CostCentreService(),
    ) {}

    /**
     * Treasury Dimensions list (plan §8 menu): bill payments that
     * settle a bill, and receipts (customer invoice payments), each
     * with its dimension split and any warnings. Tabs by ?type=.
     */
    public function index(Request $request)
    {
        if (! Auth::user()->can('manage treasury dimensions')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $type = $request->input('type', TreasuryLineDimension::TYPE_BILL_PAYMENT);
        if (! in_array($type, [TreasuryLineDimension::TYPE_BILL_PAYMENT, TreasuryLineDimension::TYPE_RECEIPT], true)) {
            $type = TreasuryLineDimension::TYPE_BILL_PAYMENT;
        }

        if ($type === TreasuryLineDimension::TYPE_RECEIPT) {
            $invoiceIds = Invoice::where('created_by', $creatorId)->pluck('id');
            $payments = InvoicePayment::whereIn('invoice_id', $invoiceIds)
                ->orderByDesc('id')->paginate(25);
            $paymentType = TreasuryLineDimension::TYPE_RECEIPT;
        } else {
            $billIds = Bill::where('created_by', $creatorId)->pluck('id');
            $payments = BillPayment::whereIn('bill_id', $billIds)
                ->orderByDesc('id')->paginate(25);
            $paymentType = TreasuryLineDimension::TYPE_BILL_PAYMENT;
        }

        // Dimensions + warnings for this page, in bulk where possible.
        $dimensionsByPayment = TreasuryLineDimension::forCompany($creatorId)
            ->where('payment_type', $paymentType)
            ->whereIn('payment_id', $payments->pluck('id')->all())
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get()
            ->groupBy('payment_id');

        $warningsByPayment = [];
        foreach ($payments as $payment) {
            $warningsByPayment[(int) $payment->id] = $this->service->warningsForPayment($paymentType, (int) $payment->id, $creatorId);
        }

        return view('cost-centres.treasury-dimensions.index', compact(
            'payments', 'type', 'paymentType', 'dimensionsByPayment', 'warningsByPayment'
        ));
    }

    /** Override form for one bill payment: inherited preview + current split + warnings. */
    public function edit(BillPayment $payment)
    {
        if (! Auth::user()->can('manage treasury dimensions')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $bill = $this->service->resolveBillForPayment($payment, $creatorId);
        $dimensions = $this->service->dimensionsForPayment(TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId);
        $preview = $this->service->inheritedPreviewForBillPayment($payment, $creatorId);
        $warnings = $this->service->warningsForPayment(TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId);
        $centres = $this->leafCentres($creatorId);
        $projects = $this->projects($creatorId);

        return view('cost-centres.treasury-dimensions.edit', [
            'payment' => $payment,
            'bill' => $bill,
            'isReceipt' => false,
            'dimensions' => $dimensions,
            'preview' => $preview,
            'warnings' => $warnings,
            'centres' => $centres,
            'projects' => $projects,
            'paymentAmount' => (string) ($payment->amount ?? 0),
        ]);
    }

    /** Apply an override split to a bill payment (or reset to inherited). */
    public function update(Request $request, BillPayment $payment)
    {
        if (! Auth::user()->can('manage treasury dimensions')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();

        try {
            if ($request->boolean('reset_to_inherited')) {
                $this->service->removeForPayment(
                    TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId, Auth::id(),
                    __('Reset to the bill’s dimensions.')
                );
                $this->service->inheritForBillPayment($payment, $creatorId, Auth::id());

                return redirect()->route('treasury-dimensions.index')
                    ->with('success', __('Payment dimensions reset to the bill’s dimensions.'));
            }

            $basis = (string) ($request->input('basis', 'percentage'));
            $lines = $this->normaliseInputLines($request->input('lines', []));
            $projectId = $request->filled('project_id') ? (int) $request->input('project_id') : null;

            $this->service->applyDimensions(
                TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id,
                $lines, $basis, $creatorId, Auth::id(), $projectId
            );
        } catch (CostCentreException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('treasury-dimensions.index')
            ->with('success', __('Payment dimensions successfully saved.'));
    }

    /** Tag form for one receipt (customer invoice payment). Analysis only. */
    public function editReceipt(InvoicePayment $receipt)
    {
        if (! Auth::user()->can('manage treasury dimensions')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $invoice = Invoice::findOrFail((int) $receipt->invoice_id);
        if ((int) $invoice->created_by !== $creatorId) {
            abort(403, __('This receipt belongs to another company.'));
        }

        $dimensions = $this->service->dimensionsForPayment(TreasuryLineDimension::TYPE_RECEIPT, (int) $receipt->id, $creatorId);
        $centres = $this->leafCentres($creatorId);
        $projects = $this->projects($creatorId);

        return view('cost-centres.treasury-dimensions.edit', [
            'payment' => $receipt,
            'bill' => $invoice,
            'isReceipt' => true,
            'dimensions' => $dimensions,
            'preview' => null,
            'warnings' => [],
            'centres' => $centres,
            'projects' => $projects,
            'paymentAmount' => (string) ($receipt->amount ?? 0),
        ]);
    }

    /** Apply tags to a receipt. Never touches money — dimensions only. */
    public function updateReceipt(Request $request, InvoicePayment $receipt)
    {
        if (! Auth::user()->can('manage treasury dimensions')) {
            return $this->deny($request);
        }

        $creatorId = Auth::user()->creatorId();

        try {
            $basis = (string) ($request->input('basis', 'percentage'));
            $lines = $this->normaliseInputLines($request->input('lines', []));
            $projectId = $request->filled('project_id') ? (int) $request->input('project_id') : null;

            $this->service->tagReceipt($receipt, $lines, $basis, $creatorId, Auth::id(), $projectId);
        } catch (CostCentreException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'errors' => [$e->getMessage()]], 422);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('treasury-dimensions.index', ['type' => TreasuryLineDimension::TYPE_RECEIPT])
            ->with('success', __('Receipt successfully tagged. Tagging never changes the invoice or the bank balance.'));
    }

    /**
     * Validation endpoint for the edit form: returns the computed
     * split + errors WITHOUT saving anything. The amount always comes
     * from the payment row server-side, never from the client.
     */
    public function validateSplit(Request $request)
    {
        if (! Auth::user()->can('manage treasury dimensions')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $creatorId = Auth::user()->creatorId();
        $paymentType = (string) ($request->input('payment_type', TreasuryLineDimension::TYPE_BILL_PAYMENT));
        $paymentId = (int) ($request->input('payment_id', 0));
        $basis = (string) ($request->input('basis', 'percentage'));
        $lines = $this->normaliseInputLines($request->input('lines', []));

        try {
            if ($paymentType === TreasuryLineDimension::TYPE_RECEIPT) {
                $payment = $this->service->resolveReceipt($paymentId, $creatorId);
            } else {
                $payment = $this->service->resolveBillPayment($paymentId, $creatorId);
            }
            $amount = (string) ($payment->amount ?? 0);
            $computed = app(\App\Services\AllocationEngine::class)->compute($amount, $lines, $basis);

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
        if (! Auth::user()->can('manage treasury dimensions')) {
            return response()->json(['ok' => false, 'errors' => [__('Permission Denied.')]], 403);
        }

        $centres = $this->leafCentres(Auth::user()->creatorId())
            ->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name])
            ->values();

        return response()->json(['ok' => true, 'centres' => $centres]);
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
