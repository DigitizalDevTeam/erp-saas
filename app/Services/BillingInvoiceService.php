<?php

namespace App\Services;

use App\Exceptions\BillingContractException;
use App\Mail\CustomerInvoiceSend;
use App\Models\BillingContract;
use App\Models\BillingContractAuditEvent;
use App\Models\BillingContractOccurrence;
use App\Models\BillingDeliveryAttempt;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\User;
use App\Models\Utility;
use App\Support\LoggedMail;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Contract Management Phase 2 — recurring invoice generation
 * (plan §2, §6, §7; AC02/AC03/AC07/AC08/AC12).
 *
 * One due occurrence → exactly one correct sales invoice, through
 * the ONE shared invoice path (InvoiceCreationService, the same
 * code InvoiceController::store() uses). The invoice and its
 * occurrence link are created in ONE database transaction; the
 * occurrence row is claimed under a lock first, so two workers
 * processing the same occurrence at the same time create ONE
 * invoice (AC03).
 *
 * Email is sent AFTER the transaction commits, so a failed send
 * can never roll the invoice back or duplicate it: the retry
 * re-sends the SAME invoice with the SAME stored PDF (AC08).
 * Backoff is 5 min → 30 min → 2 hours, then final (4 sends max:
 * the first attempt + 3 retries). A manual resend ignores
 * next_retry_at but still counts as an attempt.
 *
 * Approval mode (AC07): an approval-mode contract sends nothing
 * and uses no invoice number until it is approved — occurrences
 * only exist for active contracts, and the engine refuses any
 * occurrence whose contract is not active or not approved.
 *
 * Partial payments (AC12): a payment on a contract invoice only
 * reduces its balance (InvoicePayment + getDue()); the occurrence
 * stays 'invoiced' and no second invoice is ever issued for the
 * same period.
 *
 * Money is bcmath only, never float. The invoice subtotal equals
 * the occurrence amount exactly: per-line amounts are scaled with
 * the same covered/full formula the occurrence used, and the
 * rounding residual lands on the final line (plan §7).
 */
class BillingInvoiceService
{
    /** Sends per invoice: first attempt + 3 retries. */
    public const MAX_DELIVERY_ATTEMPTS = 4;

    /** Minutes to wait after attempt 1, 2 and 3 fail. */
    public const RETRY_DELAYS_MINUTES = [5, 30, 120];

    public function __construct(
        protected InvoiceCreationService $invoices,
        protected BillingOccurrenceService $occurrences,
    ) {
    }

    // ------------------------------------------------------------------
    // Generation
    // ------------------------------------------------------------------

    /**
     * Issue the invoice for one due occurrence. Idempotent: a second
     * call returns the existing invoice and creates nothing.
     *
     * @return array{invoice: Invoice, occurrence: BillingContractOccurrence, already_invoiced: bool, email: string}
     */
    public function generateForOccurrence(
        BillingContractOccurrence $occurrence,
        int $userId,
        ?Carbon $asOf = null,
        bool $sendEmail = true,
    ): array {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $creatorId = (int) $occurrence->created_by;
        $this->ensureAuthContext($creatorId);

        $result = DB::transaction(function () use ($occurrence, $userId, $asOf, $creatorId) {
            // Claim the occurrence under a row lock: a concurrent
            // worker blocks here, then sees the invoiced state and
            // returns the existing invoice (AC03).
            $locked = BillingContractOccurrence::where('id', $occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();
            $contract = BillingContract::where('id', $locked->contract_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $contract->created_by !== $creatorId) {
                throw new BillingContractException('Occurrence does not belong to this company.');
            }

            if (in_array($locked->state, [
                BillingContractOccurrence::STATE_INVOICED,
                BillingContractOccurrence::STATE_GENERATED,
            ], true) && $locked->invoice_id) {
                return [
                    'invoice' => Invoice::findOrFail($locked->invoice_id),
                    'occurrence' => $locked->fresh(),
                    'contract' => $contract,
                    'already_invoiced' => true,
                    'email' => 'already_invoiced',
                ];
            }

            if ($locked->state !== BillingContractOccurrence::STATE_PENDING) {
                throw new BillingContractException(
                    "Occurrence {$locked->period_key} is {$locked->state} and cannot be invoiced."
                );
            }

            if ($contract->state !== BillingContract::STATE_ACTIVE) {
                throw new BillingContractException(
                    "Contract {$contract->reference} is {$contract->state}; only active contracts bill."
                );
            }

            // AC07: approval mode sends nothing and uses no invoice
            // number until approved. Occurrences only exist after
            // approval, but refuse explicitly — never trust the row.
            if ($contract->approval_mode === BillingContract::APPROVAL_MODE_APPROVAL
                && empty($contract->approved_at)
            ) {
                throw new BillingContractException(
                    "Contract {$contract->reference} is in approval mode and has not been approved."
                );
            }

            if (Carbon::parse($locked->billing_date)->greaterThan($asOf)) {
                throw new BillingContractException(
                    "Occurrence {$locked->period_key} is not due until {$locked->billing_date->format('Y-m-d')}."
                );
            }

            $contract->loadMissing(['lines', 'customer']);

            if ($contract->lines->isEmpty()) {
                throw new BillingContractException(
                    "Contract {$contract->reference} has no billing lines."
                );
            }

            $lines = $contract->lines->sortBy('id')->values();

            $issueDate = $asOf->format('Y-m-d');
            $dueDate = $asOf->copy()->addDays(max(0, (int) $contract->payment_terms_days))->format('Y-m-d');

            // Claim first: the occurrence is 'generated' from here
            // until the invoice link lands below, all in this one
            // transaction — a failure rolls everything back.
            $locked->update(['state' => BillingContractOccurrence::STATE_GENERATED, 'generated_at' => now()]);

            $items = $this->buildInvoiceItems($contract, $locked, $lines);
            $positionOverrides = $this->revenueAccountOverrides($contract, $lines, $items);

            $invoice = $this->invoices->createInvoice($creatorId, [
                'customer_id' => $contract->customer_id,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'category_id' => $this->resolveCategoryId($creatorId),
                'ref_number' => $contract->reference,
                'items' => $items,
            ]);

            // The recurring invoice is issued, not left as a draft:
            // mark it sent and post it to the ledger in the same
            // transaction, exactly like a manual invoice's release.
            $invoice->send_date = $issueDate;
            $invoice->status = Invoice::STATUS_SENT;
            $invoice->save();

            // postInvoiceToLedger keys overrides by invoice_product
            // id; products are created in item order, so map the
            // position-based accounts onto the created rows.
            $overrides = [];
            foreach ($invoice->products()->orderBy('id')->get() as $position => $product) {
                if (array_key_exists($position, $positionOverrides)) {
                    $overrides[$product->id] = $positionOverrides[$position];
                }
            }
            $this->invoices->postInvoiceToLedger($invoice, $creatorId, $overrides);

            $locked->update([
                'state' => BillingContractOccurrence::STATE_INVOICED,
                'invoice_id' => $invoice->id,
            ]);

            $this->logEvent(
                $contract,
                'invoice_issued',
                $userId,
                null,
                ['occurrence' => $locked->period_key, 'state' => 'pending'],
                [
                    'occurrence' => $locked->period_key,
                    'state' => 'invoiced',
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_id,
                    'amount' => (string) $locked->amount,
                ],
                (int) $locked->id
            );

            return [
                'invoice' => $invoice->fresh(),
                'occurrence' => $locked->fresh(),
                'contract' => $contract,
                'already_invoiced' => false,
                'email' => 'pending',
            ];
        });

        // Email AFTER the commit: a failed send must never roll
        // the invoice back, and a retry re-sends this same invoice
        // (AC08).
        if ($result['already_invoiced']) {
            return $result;
        }

        if ($sendEmail && $result['contract']->delivery_auto_email) {
            $email = $this->sendInvoiceEmail(
                $result['contract'],
                $result['occurrence'],
                $result['invoice'],
                $userId
            );
            $result['email'] = $email['status'];
        } else {
            $result['email'] = $sendEmail ? 'disabled' : 'skipped';
        }

        return $result;
    }

    /** Due occurrences for a company (pending, billing date reached, contract active). */
    public function dueOccurrences(int $creatorId, ?Carbon $asOf = null)
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();

        return BillingContractOccurrence::where('created_by', $creatorId)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('billing_date', '<=', $asOf->format('Y-m-d'))
            ->whereHas('contract', fn ($q) => $q->where('state', BillingContract::STATE_ACTIVE))
            ->with(['contract.customer'])
            ->orderBy('billing_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Generate all due occurrences for one contract. A failure for
     * one occurrence never blocks the others.
     *
     * @return array{generated: int, already_invoiced: int, failed: int, failures: array}
     */
    public function generateDueForContract(
        BillingContract $contract,
        int $userId,
        ?Carbon $asOf = null,
        bool $sendEmail = true,
    ): array {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();

        $results = ['generated' => 0, 'already_invoiced' => 0, 'failed' => 0, 'failures' => []];

        $due = BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('billing_date', '<=', $asOf->format('Y-m-d'))
            ->orderBy('billing_date')
            ->orderBy('id')
            ->get();

        foreach ($due as $occurrence) {
            try {
                $r = $this->generateForOccurrence($occurrence, $userId, $asOf, $sendEmail);
                if ($r['already_invoiced']) {
                    $results['already_invoiced']++;
                } else {
                    $results['generated']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['failures'][] = [
                    'occurrence_id' => $occurrence->id,
                    'period_key' => $occurrence->period_key,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** Generate all due occurrences for a company, one contract at a time. */
    public function generateDueForCompany(
        int $creatorId,
        int $userId,
        ?Carbon $asOf = null,
        bool $sendEmail = true,
    ): array {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $totals = ['generated' => 0, 'already_invoiced' => 0, 'failed' => 0, 'failures' => []];

        foreach ($this->dueOccurrences($creatorId, $asOf) as $occurrence) {
            try {
                $r = $this->generateForOccurrence($occurrence, $userId, $asOf, $sendEmail);
                if ($r['already_invoiced']) {
                    $totals['already_invoiced']++;
                } else {
                    $totals['generated']++;
                }
            } catch (\Throwable $e) {
                $totals['failed']++;
                $totals['failures'][] = [
                    'occurrence_id' => $occurrence->id,
                    'period_key' => $occurrence->period_key,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $totals;
    }

    // ------------------------------------------------------------------
    // Email delivery (AC08)
    // ------------------------------------------------------------------

    /**
     * Send (or retry) the email for an issued invoice. Always the
     * SAME invoice and the SAME stored PDF — a retry never creates
     * a new invoice or a new number. $manual ignores next_retry_at
     * (an operator explicitly resending); scheduled retries only
     * run when the backoff has elapsed.
     *
     * @return array{status: string, attempt: BillingDeliveryAttempt}
     */
    public function sendInvoiceEmail(
        BillingContract $contract,
        BillingContractOccurrence $occurrence,
        Invoice $invoice,
        int $userId,
        bool $manual = false,
    ): array {
        $this->ensureAuthContext((int) $contract->created_by);

        $attempt = BillingDeliveryAttempt::firstOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'created_by' => (int) $contract->created_by,
                'contract_id' => $contract->id,
                'occurrence_id' => $occurrence->id,
                'channel' => 'email',
                'status' => BillingDeliveryAttempt::STATUS_QUEUED,
                'attempt_count' => 0,
            ]
        );

        // A sent delivery is done — unless an operator explicitly
        // resends it, which counts as a new attempt.
        if ($attempt->status === BillingDeliveryAttempt::STATUS_SENT && ! $manual) {
            return ['status' => 'sent', 'attempt' => $attempt];
        }

        if (! $manual
            && $attempt->status === BillingDeliveryAttempt::STATUS_FAILED
            && $attempt->next_retry_at !== null
            && $attempt->next_retry_at->isFuture()
        ) {
            return ['status' => 'waiting_retry', 'attempt' => $attempt];
        }

        $customer = $contract->customer ?? Customer::find($contract->customer_id);
        $to = trim((string) ($contract->delivery_to ?? ''));
        if ($to === '') {
            $to = $customer ? (string) ($customer->operationalEmail() ?? '') : '';
        }
        $cc = $this->splitEmails($contract->delivery_cc);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $attempt->update([
                'status' => BillingDeliveryAttempt::STATUS_FAILED,
                'attempt_count' => $attempt->attempt_count + 1,
                'next_retry_at' => null,
                'last_error' => 'No recipient email address on the contract or the customer.',
            ]);
            $this->logEvent($contract, 'invoice_email_failed', $userId,
                'No recipient email address.', null,
                ['invoice_id' => $invoice->id], (int) $occurrence->id);

            return ['status' => 'failed', 'attempt' => $attempt->fresh()];
        }

        $invoice->name = $customer ? $customer->name : '';
        $invoice->invoice = $this->invoiceNumberFormat((int) $contract->created_by, (int) $invoice->invoice_id);
        $invoice->url = route('invoice.pdf', Crypt::encrypt($invoice->id));

        $pdf = $this->storedPdfBytes($invoice);

        try {
            LoggedMail::send(
                $to,
                new CustomerInvoiceSend(
                    $invoice,
                    $pdf,
                    'invoice-' . $invoice->invoice_id . '.pdf',
                    $cc
                ),
                [
                    'source' => 'billing_contract_invoice',
                    'subject' => __('Invoice') . ' ' . $invoice->invoice,
                    'created_by' => (int) $contract->created_by,
                    'user_id' => $userId,
                ]
            );

            $attempt->update([
                'status' => BillingDeliveryAttempt::STATUS_SENT,
                'attempt_count' => $attempt->attempt_count + 1,
                'recipients_to' => $to,
                'recipients_cc' => $cc !== [] ? implode(', ', $cc) : null,
                'next_retry_at' => null,
                'last_error' => null,
                'sent_at' => now(),
            ]);
            $this->logEvent($contract, 'invoice_email_sent', $userId, null, null,
                ['invoice_id' => $invoice->id, 'to' => $to], (int) $occurrence->id);

            return ['status' => 'sent', 'attempt' => $attempt->fresh()];
        } catch (\Throwable $e) {
            $count = $attempt->attempt_count + 1;
            $next = $count < self::MAX_DELIVERY_ATTEMPTS
                ? now()->addMinutes(self::RETRY_DELAYS_MINUTES[$count - 1])
                : null;

            $attempt->update([
                'status' => BillingDeliveryAttempt::STATUS_FAILED,
                'attempt_count' => $count,
                'recipients_to' => $to,
                'recipients_cc' => $cc !== [] ? implode(', ', $cc) : null,
                'next_retry_at' => $next,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            $this->logEvent($contract, 'invoice_email_failed', $userId,
                $e->getMessage(), null,
                ['invoice_id' => $invoice->id, 'attempt' => $count], (int) $occurrence->id);

            return ['status' => 'failed', 'attempt' => $attempt->fresh()];
        }
    }

    /** Retry every failed delivery whose backoff has elapsed. */
    public function retryDueDeliveries(int $creatorId, int $userId): array
    {
        $results = ['retried' => 0, 'sent' => 0, 'failed' => 0];

        $due = BillingDeliveryAttempt::where('created_by', $creatorId)
            ->where('status', BillingDeliveryAttempt::STATUS_FAILED)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->get();

        foreach ($due as $attempt) {
            $contract = BillingContract::find($attempt->contract_id);
            $occurrence = BillingContractOccurrence::find($attempt->occurrence_id);
            $invoice = Invoice::find($attempt->invoice_id);
            if (! $contract || ! $occurrence || ! $invoice) {
                $attempt->update(['next_retry_at' => null]);
                $results['failed']++;
                continue;
            }

            $results['retried']++;
            $r = $this->sendInvoiceEmail($contract, $occurrence, $invoice, $userId);
            if ($r['status'] === 'sent') {
                $results['sent']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /** Manual resend of an issued invoice's email (same invoice, same PDF). */
    public function resendInvoice(BillingContractOccurrence $occurrence, int $userId): array
    {
        if ($occurrence->state !== BillingContractOccurrence::STATE_INVOICED || ! $occurrence->invoice_id) {
            throw new BillingContractException('Only an invoiced occurrence can be resent.');
        }

        $contract = BillingContract::findOrFail($occurrence->contract_id);
        $invoice = Invoice::findOrFail($occurrence->invoice_id);

        if ((int) $contract->created_by !== (int) $occurrence->created_by) {
            throw new BillingContractException('Occurrence does not belong to this company.');
        }

        return $this->sendInvoiceEmail($contract, $occurrence, $invoice, $userId, true);
    }

    // ------------------------------------------------------------------
    // Invoice lines
    // ------------------------------------------------------------------

    /**
     * Invoice items for one occurrence, from the contract's current
     * lines. The subtotal equals the occurrence amount exactly:
     * each line is scaled with the same covered/full formula the
     * occurrence used, and the rounding residual lands on the
     * final line (plan §7).
     *
     * invoice_products.price is decimal(16,2) and quantity is an
     * integer, so a fractional contract quantity is folded into
     * the price (quantity 1, price = unit price x quantity) and
     * the proration adjustment rides on the line discount — the
     * unit price and quantity the customer sees never change.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildInvoiceItems(BillingContract $contract, BillingContractOccurrence $occurrence, $lines): array
    {
        $periodStart = Carbon::parse($occurrence->period_start)->startOfDay();
        $periodEnd = Carbon::parse($occurrence->period_end)->startOfDay();
        $coverage = $this->occurrences->periodCoverage($contract, $periodStart, $periodEnd);
        $prorate = $coverage['partial']
            && $contract->partial_period_rule === BillingContract::PARTIAL_PRORATE;

        $scaled = [];
        foreach ($lines as $line) {
            $base = bcadd((string) $line->amount, '0', 4);
            if ($prorate) {
                $scaled[] = $this->bcRound4(bcdiv(
                    bcmul($base, (string) $coverage['covered_days'], 6),
                    (string) $coverage['full_days'],
                    6
                ));
            } else {
                $scaled[] = $base;
            }
        }

        // Residual on the final line so the invoice subtotal equals
        // the occurrence amount to the 4th decimal.
        $sum = '0.0000';
        foreach ($scaled as $amount) {
            $sum = bcadd($sum, $amount, 4);
        }
        $residual = bcsub((string) $occurrence->amount, $sum, 4);
        if ($residual !== '0.0000' && $scaled !== []) {
            $last = count($scaled) - 1;
            $scaled[$last] = bcadd($scaled[$last], $residual, 4);
        }

        $coverageLabel = $periodStart->format('Y-m-d') . ' → ' . $periodEnd->copy()->subDay()->format('Y-m-d');
        $items = [];
        foreach ($lines as $i => $line) {
            // invoice_products.product_id is NOT NULL: a contract
            // line with no product cannot become an invoice line.
            // Refuse with a clear message naming the line instead
            // of crashing on the insert.
            if (empty($line->product_id)
                || ! ProductService::where('id', $line->product_id)->where('created_by', (int) $contract->created_by)->exists()
            ) {
                throw new BillingContractException(
                    "Billing line \"{$line->description}\" has no product for this company and cannot be invoiced."
                );
            }

            $quantity = (string) $line->quantity;
            $unitPrice = (string) $line->unit_price;
            $wholeQty = bccomp($quantity, (string) (int) $quantity, 4) === 0;

            if ($wholeQty) {
                $qtyInv = (int) $quantity;
                $priceInv = $this->round2($unitPrice);
            } else {
                $qtyInv = 1;
                $priceInv = $this->round2(bcmul($unitPrice, $quantity, 6));
            }

            $gross = bcmul((string) $priceInv, (string) $qtyInv, 4);
            // Discount carries the proration (and any folding
            // rounding): line amount = gross - discount = scaled.
            $discount = bcsub($gross, $scaled[$i], 4);
            if (bccomp($discount, '0', 4) < 0) {
                // Cannot happen for whole quantities (scaled <= base
                // <= gross); for folded quantities the 2dp price may
                // round down a fraction of a cent — lift the price
                // instead of posting a negative discount.
                $priceInv = $this->round2(bcadd((string) $priceInv, bcdiv(bcsub('0', $discount, 4), (string) $qtyInv, 6), 2));
                $gross = bcmul((string) $priceInv, (string) $qtyInv, 4);
                $discount = bcsub($gross, $scaled[$i], 4);
                if (bccomp($discount, '0', 4) < 0) {
                    $discount = '0.0000';
                }
            }

            $description = $line->description . ' — ' . $coverageLabel;
            if ($prorate) {
                $description .= ' (' . __('prorated') . ': ' . $coverage['covered_days']
                    . ' / ' . $coverage['full_days'] . ' ' . __('days') . ')';
            }

            $items[] = [
                'product_id' => $line->product_id,
                'quantity' => $qtyInv,
                'tax' => $line->tax_id !== null ? (string) $line->tax_id : null,
                'discount' => (float) $discount,
                'price' => (float) $priceInv,
                'description' => $description,
                'contract_line_id' => $line->id,
                'scaled_amount' => $scaled[$i],
            ];
        }

        return $items;
    }

    /**
     * Revenue account per invoice line: the contract line's chart
     * account wins, else the product's sale account. Every line
     * must resolve to an account — transaction_lines.account_id
     * is NOT NULL, so a line without one would crash the posting
     * with a cryptic SQL error instead of a clear refusal.
     *
     * @param  \Illuminate\Support\Collection  $lines  contract lines, ordered by id
     * @return array<int, int> position in the items list => account id
     */
    protected function revenueAccountOverrides(BillingContract $contract, $lines, array $items): array
    {
        // Overrides are keyed by invoice_product id, which only
        // exists after creation — so resolve accounts per contract
        // line here (position-based) and map them onto the created
        // rows in generateForOccurrence().
        $overrides = [];
        foreach ($items as $position => $item) {
            $line = $lines->values()->get($position);
            $accountId = $line && $line->chart_account_id
                ? (int) $line->chart_account_id
                : null;
            if ($accountId === null) {
                $product = ProductService::find($item['product_id']);
                $accountId = $product && $product->sale_chartaccount_id
                    ? (int) $product->sale_chartaccount_id
                    : null;
            }
            if ($accountId === null) {
                $label = $line ? $line->description : ('line ' . ($position + 1));
                throw new BillingContractException(
                    "Billing line \"{$label}\" has no revenue account (no chart account on the line and no sale account on the product)."
                );
            }
            $overrides[$position] = $accountId;
        }

        return $overrides;
    }

    /** First income category for the company (invoices.category_id is NOT NULL). */
    protected function resolveCategoryId(int $creatorId): int
    {
        $category = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 'income')
            ->orderBy('id')
            ->first();

        if (! $category) {
            throw new BillingContractException(
                'No income category exists for this company; a recurring invoice needs one.'
            );
        }

        return (int) $category->id;
    }

    // ------------------------------------------------------------------
    // PDF (AC08: the same stored PDF on every retry)
    // ------------------------------------------------------------------

    /** PDF bytes for an invoice, generated once and stored for retries. */
    public function storedPdfBytes(Invoice $invoice): string
    {
        $path = 'billing-invoice-pdfs/' . $invoice->id . '.pdf';

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }

        $bytes = $this->invoicePdfBytes($invoice);
        Storage::disk('local')->put($path, $bytes);

        BillingDeliveryAttempt::where('invoice_id', $invoice->id)
            ->update(['pdf_path' => $path]);

        return $bytes;
    }

    /** Render the invoice PDF from the invoice's own data. */
    public function invoicePdfBytes(Invoice $invoice): string
    {
        $invoice->loadMissing(['items.product', 'customer']);
        $settings = Utility::settingsById((int) $invoice->created_by);

        return Pdf::loadView('billing-contracts.invoice-pdf', [
            'invoice' => $invoice,
            'customer' => $invoice->customer,
            'settings' => $settings,
            'number' => ($settings['invoice_prefix'] ?? '#Fatura') . sprintf('%05d', (int) $invoice->invoice_id),
        ])->output();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function invoiceNumberFormat(int $creatorId, int $number): string
    {
        $settings = Utility::settingsById($creatorId);

        return ($settings['invoice_prefix'] ?? '#Fatura') . sprintf('%05d', $number);
    }

    /** @return string[] */
    protected function splitEmails(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($email) => filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : null,
            preg_split('/[,\s;]+/', $value) ?: []
        )));
    }

    protected function bcRound4(string $value): string
    {
        return number_format(round((float) $value, 4), 4, '.', '');
    }

    protected function round2(string $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /**
     * Shared helpers (stock deduction, email logging) read the
     * authenticated user. Web requests already have one; the
     * scheduler and tests do not — log in as the company user so
     * the engine behaves exactly like the web path.
     */
    protected function ensureAuthContext(int $creatorId): void
    {
        if (Auth::check()) {
            return;
        }

        $user = User::find($creatorId);
        if (! $user) {
            throw new BillingContractException(
                "No company user {$creatorId} exists to run billing for."
            );
        }

        Auth::login($user);
    }

    protected function logEvent(
        BillingContract $contract,
        string $action,
        ?int $actorId,
        ?string $reason,
        ?array $before,
        ?array $after,
        ?int $occurrenceId = null,
    ): void {
        BillingContractAuditEvent::create([
            'contract_id' => $contract->id,
            'occurrence_id' => $occurrenceId,
            'actor_id' => $actorId,
            'action' => $action,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
            'created_by' => (int) $contract->created_by,
        ]);
    }
}
