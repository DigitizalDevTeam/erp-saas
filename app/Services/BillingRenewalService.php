<?php

namespace App\Services;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractAuditEvent;
use App\Models\BillingRenewal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 3 — renewals (plan §4, §6).
 *
 * A renewal is a fresh contract draft copied from the old one
 * (same customer, lines, frequency, terms) with a new start
 * date (old end date + 1 day), a new reference, and a new
 * occurrence stream that starts at its own start date — so a
 * renewal never re-bills periods already invoiced under the
 * old contract. The renewal draft goes through the same
 * approval flow as a new contract (no self-approval, via
 * BillingContractService::createContract/submit/approve).
 * When the new contract becomes active, the renewal event is
 * marked renewed; abandoning a draft marks it not_renewed.
 * "Contracts ending soon" = active or suspended contracts
 * whose end date falls within the next 30 days (or the given
 * window).
 *
 * All queries are scoped by creatorId() (AC11).
 */
class BillingRenewalService
{
    public function __construct(
        protected BillingContractService $contracts,
    ) {
    }

    // ------------------------------------------------------------------
    // Ending soon
    // ------------------------------------------------------------------

    /**
     * Active or suspended contracts of a company whose end date
     * falls within the next $days days (default 30).
     */
    public function endingSoon(int $creatorId, int $days = 30, ?Carbon $asOf = null)
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $until = $asOf->copy()->addDays($days);

        return BillingContract::where('created_by', $creatorId)
            ->whereIn('state', [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $asOf->format('Y-m-d'))
            ->whereDate('end_date', '<=', $until->format('Y-m-d'))
            ->with(['customer', 'lines'])
            ->orderBy('end_date')
            ->orderBy('id')
            ->get();
    }

    // ------------------------------------------------------------------
    // Renewal
    // ------------------------------------------------------------------

    /** Whether a contract can be renewed right now. */
    public function canRenew(BillingContract $contract): bool
    {
        if ($contract->end_date === null) {
            return false;
        }

        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_ENDED], true)) {
            return false;
        }

        return ! $this->hasPendingRenewal($contract);
    }

    public function hasPendingRenewal(BillingContract $contract): bool
    {
        // The renewals table arrives with the Phase 3 migration;
        // before it runs (e.g. a site pulled but not migrated yet)
        // there can be no pending renewal.
        if (! Schema::hasTable('billing_renewals')) {
            return false;
        }

        return BillingRenewal::where('contract_id', $contract->id)
            ->where('status', BillingRenewal::STATUS_PENDING)
            ->exists();
    }

    /** The pending renewal of a contract, if any. */
    public function pendingRenewal(BillingContract $contract): ?BillingRenewal
    {
        if (! Schema::hasTable('billing_renewals')) {
            return null;
        }

        return BillingRenewal::where('contract_id', $contract->id)
            ->where('status', BillingRenewal::STATUS_PENDING)
            ->with('newContract')
            ->first();
    }

    /** Renewal events for a contract, newest first (empty before the Phase 3 migration). */
    public function renewalHistory(BillingContract $contract)
    {
        if (! Schema::hasTable('billing_renewals')) {
            return collect();
        }

        return $contract->renewals()->with('newContract')->orderByDesc('id')->get();
    }

    /**
     * Create a renewal draft from a contract. $overrides may
     * change start_date, end_date or title on the form; the rest
     * is copied from the old contract. Returns the new draft.
     */
    public function renew(BillingContract $contract, int $userId, array $overrides = []): BillingContract
    {
        if ($contract->end_date === null) {
            throw new BillingContractException('Only a contract with an end date can be renewed.');
        }

        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_ENDED], true)) {
            throw new BillingContractException('Only an active or ended contract can be renewed.');
        }

        if ($this->hasPendingRenewal($contract)) {
            throw new BillingContractException('This contract already has a pending renewal draft.');
        }

        $creatorId = (int) $contract->created_by;
        $oldEnd = Carbon::parse($contract->end_date)->startOfDay();
        $oldStart = Carbon::parse($contract->start_date)->startOfDay();

        $newStart = $oldEnd->copy()->addDay();
        // Keep the same term length: end = start + (old term days).
        $termDays = $oldStart->diffInDays($oldEnd);
        $newEnd = $newStart->copy()->addDays($termDays);

        $startDate = $overrides['start_date'] ?? $newStart->format('Y-m-d');
        $endDate = array_key_exists('end_date', $overrides) ? $overrides['end_date'] : $newEnd->format('Y-m-d');

        $lines = $contract->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'discount_amount' => $line->discount_amount,
            'tax_id' => $line->tax_id,
            'chart_account_id' => $line->chart_account_id,
            'cost_centre_id' => $line->cost_centre_id,
        ])->all();

        $data = [
            'customer_id' => $contract->customer_id,
            'title' => $overrides['title'] ?? $contract->title,
            'description' => $contract->description,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'currency' => $contract->currency,
            'exchange_rate' => $contract->exchange_rate,
            'billing_frequency' => $contract->billing_frequency,
            'custom_interval' => $contract->custom_interval,
            'custom_unit' => $contract->custom_unit,
            'anchor_day' => $contract->anchor_day,
            'billing_mode' => $contract->billing_mode,
            'payment_terms_days' => $contract->payment_terms_days,
            'approval_mode' => $contract->approval_mode,
            'proration_enabled' => (bool) $contract->proration_enabled,
            'partial_period_rule' => $contract->partial_period_rule,
            'renewal_notice_days' => $contract->renewal_notice_days,
            'auto_renew' => (bool) $contract->auto_renew,
            'delivery_auto_email' => (bool) $contract->delivery_auto_email,
            'delivery_to' => $contract->delivery_to,
            'delivery_cc' => $contract->delivery_cc,
            'notes' => $contract->notes,
            'branch_id' => $contract->branch_id,
            'lines' => $lines,
        ];

        return DB::transaction(function () use ($contract, $creatorId, $userId, $data, $newStart) {
            $new = $this->contracts->createContract($creatorId, $data, $userId);
            $new->update(['renewed_from_id' => $contract->id]);

            BillingRenewal::create([
                'created_by' => $creatorId,
                'contract_id' => $contract->id,
                'new_contract_id' => $new->id,
                'effective_date' => $newStart->format('Y-m-d'),
                'type' => BillingRenewal::TYPE_MANUAL,
                'status' => BillingRenewal::STATUS_PENDING,
            ]);

            $this->logEvent($contract, 'renewal_created', $userId, null,
                ['state' => $contract->state],
                ['new_contract_id' => $new->id, 'new_reference' => $new->reference]);

            return $new->fresh();
        });
    }

    /**
     * When a contract becomes active, mark its pending renewal
     * event renewed (the renewal is complete). Called from the
     * controller after activation.
     */
    public function syncRenewalStatus(BillingContract $contract): void
    {
        if ($contract->state !== BillingContract::STATE_ACTIVE) {
            return;
        }

        if (! Schema::hasTable('billing_renewals')) {
            return;
        }

        BillingRenewal::where('new_contract_id', $contract->id)
            ->where('status', BillingRenewal::STATUS_PENDING)
            ->update(['status' => BillingRenewal::STATUS_RENEWED]);
    }

    /** Abandon a pending renewal: the old contract simply ends. */
    public function abandon(BillingRenewal $renewal, int $userId): BillingRenewal
    {
        if ($renewal->status !== BillingRenewal::STATUS_PENDING) {
            throw new BillingContractException('Only a pending renewal can be abandoned.');
        }

        $renewal->update(['status' => BillingRenewal::STATUS_NOT_RENEWED]);

        $contract = BillingContract::find($renewal->contract_id);
        if ($contract) {
            $this->logEvent($contract, 'renewal_abandoned', $userId, null,
                ['new_contract_id' => $renewal->new_contract_id],
                ['status' => BillingRenewal::STATUS_NOT_RENEWED]);
        }

        return $renewal->fresh();
    }

    protected function logEvent(
        BillingContract $contract,
        string $action,
        ?int $actorId,
        ?string $reason,
        ?array $before,
        ?array $after,
    ): void {
        BillingContractAuditEvent::create([
            'contract_id' => $contract->id,
            'occurrence_id' => null,
            'actor_id' => $actorId,
            'action' => $action,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
            'created_by' => (int) $contract->created_by,
        ]);
    }
}
