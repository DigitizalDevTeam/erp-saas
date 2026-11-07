<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\BankAccount;
use App\Models\TreasuryAdvance;
use App\Models\TreasuryCashFlowAlert;
use App\Models\TreasuryPaymentRequest;
use App\Models\TreasuryRecurringOccurrence;
use App\Models\TreasuryStatementSyncCursor;
use App\Models\TreasuryTransfer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — cash-flow alerts (plan §8, §10).
 *
 * Alert records, recomputed by the forecast-refresh job and
 * the cash-flow screen: upcoming / overdue recurring
 * occurrences, insufficient funds (forecast closing balance
 * below the company threshold), pending approvals, delayed
 * transfers, overdue advances, sync failures.
 *
 * One active alert per (company, type, source) — the refresh
 * upserts by that key, so a job run twice never duplicates an
 * alert, and resolves the alert when its condition clears. No
 * email is sent in this phase; the records + screen are the
 * deliverable.
 *
 * Company scope is creatorId() on every query (CA16).
 */
class TreasuryAlertService
{
    /** An in-transit transfer older than this is "delayed". */
    public const DELAYED_TRANSFER_DAYS = 3;

    /** Upcoming occurrences inside this window raise an alert. */
    public const UPCOMING_WINDOW_DAYS = 7;

    /** A sync failure stays alertable for this long. */
    public const SYNC_FAILURE_DAYS = 7;

    protected TreasuryForecastService $forecast;

    public function __construct(?TreasuryForecastService $forecast = null)
    {
        $this->forecast = $forecast ?? new TreasuryForecastService();
    }

    public function alertForCompany(int $creatorId, int $alertId): TreasuryCashFlowAlert
    {
        $alert = TreasuryCashFlowAlert::where('created_by', $creatorId)->find($alertId);

        if (! $alert) {
            throw new TreasuryException('Alert not found for this company.');
        }

        return $alert;
    }

    public function acknowledge(int $creatorId, int $alertId, int $actorId): TreasuryCashFlowAlert
    {
        $alert = $this->alertForCompany($creatorId, $alertId);

        if ($alert->state !== TreasuryCashFlowAlert::STATE_ACTIVE) {
            throw new TreasuryException('Only an active alert can be acknowledged.');
        }

        $alert->state = TreasuryCashFlowAlert::STATE_ACKNOWLEDGED;
        $alert->acknowledged_by = $actorId;
        $alert->acknowledged_at = now();
        $alert->save();

        return $alert->fresh();
    }

    /**
     * Recompute the company's alerts as of $asOf (today by
     * default). Returns ['raised' => n, 'resolved' => n].
     */
    public function refreshAlerts(int $creatorId, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();

        return DB::transaction(function () use ($creatorId, $asOf) {
            $detected = $this->detect($creatorId, $asOf);
            $raised = 0;

            foreach ($detected as $condition) {
                $key = $this->conditionKey($condition);
                $existing = TreasuryCashFlowAlert::where('created_by', $creatorId)
                    ->where('type', $condition['type'])
                    ->where('source_type', $condition['source_type'])
                    ->where(function ($query) use ($condition) {
                        if ($condition['source_id'] === null) {
                            $query->whereNull('source_id');
                        } else {
                            $query->where('source_id', $condition['source_id']);
                        }
                    })
                    ->whereIn('state', [TreasuryCashFlowAlert::STATE_ACTIVE, TreasuryCashFlowAlert::STATE_ACKNOWLEDGED])
                    ->first();

                if ($existing === null) {
                    TreasuryCashFlowAlert::create([
                        'company_id' => $creatorId,
                        'created_by' => $creatorId,
                        'type' => $condition['type'],
                        'severity' => $condition['severity'],
                        'title' => $condition['title'],
                        'detail' => $condition['detail'],
                        'source_type' => $condition['source_type'],
                        'source_id' => $condition['source_id'],
                        'state' => TreasuryCashFlowAlert::STATE_ACTIVE,
                        'occurred_at' => $asOf,
                    ]);
                    $raised++;
                } else {
                    // The condition still stands — keep the alert
                    // (and its acknowledgement) but refresh its text.
                    $existing->title = $condition['title'];
                    $existing->detail = $condition['detail'];
                    $existing->severity = $condition['severity'];
                    $existing->save();
                }
            }

            // Resolve alerts whose condition cleared.
            $liveKeys = array_map(fn ($c) => $this->conditionKey($c), $detected);
            $open = TreasuryCashFlowAlert::where('created_by', $creatorId)
                ->whereIn('state', [TreasuryCashFlowAlert::STATE_ACTIVE, TreasuryCashFlowAlert::STATE_ACKNOWLEDGED])
                ->get();

            $resolved = 0;
            foreach ($open as $alert) {
                $key = $alert->type . '|' . (string) $alert->source_type . '|' . (string) ($alert->source_id ?? '');
                if (! in_array($key, $liveKeys, true)) {
                    $alert->state = TreasuryCashFlowAlert::STATE_RESOLVED;
                    $alert->save();
                    $resolved++;
                }
            }

            return ['raised' => $raised, 'resolved' => $resolved];
        });
    }

    /**
     * Every condition currently standing for the company.
     * Each entry: type, severity, title, detail, source_type,
     * source_id (null for company-level alerts).
     */
    protected function detect(int $creatorId, Carbon $asOf): array
    {
        $conditions = [];

        // Overdue + upcoming recurring occurrences.
        $occurrences = TreasuryRecurringOccurrence::whereHas('rule', function ($query) use ($creatorId) {
            $query->where('created_by', $creatorId);
        })
            ->where('state', TreasuryRecurringOccurrence::STATE_GENERATED)
            ->with('rule')
            ->orderBy('scheduled_date')
            ->get();

        foreach ($occurrences as $occurrence) {
            $scheduled = Carbon::parse($occurrence->scheduled_date)->startOfDay();
            $label = ($occurrence->rule->beneficiary ?? 'Recurring') . ' — ' . number_format((float) $occurrence->amount_base, 2) . ' ' . $occurrence->currency . ' due ' . $scheduled->toDateString();

            if ($scheduled->lessThan($asOf)) {
                $conditions[] = [
                    'type' => TreasuryCashFlowAlert::TYPE_OVERDUE_OCCURRENCE,
                    'severity' => TreasuryCashFlowAlert::SEVERITY_WARNING,
                    'title' => 'Overdue recurring commitment',
                    'detail' => $label,
                    'source_type' => 'recurring_occurrence',
                    'source_id' => $occurrence->id,
                ];
            } elseif ($scheduled->lessThanOrEqualTo($asOf->copy()->addDays(self::UPCOMING_WINDOW_DAYS))) {
                $conditions[] = [
                    'type' => TreasuryCashFlowAlert::TYPE_UPCOMING_OCCURRENCE,
                    'severity' => TreasuryCashFlowAlert::SEVERITY_INFO,
                    'title' => 'Recurring commitment due soon',
                    'detail' => $label,
                    'source_type' => 'recurring_occurrence',
                    'source_id' => $occurrence->id,
                ];
            }
        }

        // Insufficient funds: the 30-day baseline forecast drops
        // under the company threshold.
        $firstBelow = $this->forecast->firstDayBelowThreshold($creatorId, 30, 'baseline');
        if ($firstBelow !== null) {
            $conditions[] = [
                'type' => TreasuryCashFlowAlert::TYPE_INSUFFICIENT_FUNDS,
                'severity' => TreasuryCashFlowAlert::SEVERITY_CRITICAL,
                'title' => 'Forecast balance below threshold',
                'detail' => 'Closing balance ' . $firstBelow['closing'] . ' on ' . $firstBelow['date'] . ' is below the threshold of ' . $this->forecast->threshold($creatorId) . '.',
                'source_type' => 'forecast',
                'source_id' => null,
            ];
        }

        // Pending approvals.
        $requests = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->whereIn('state', [TreasuryPaymentRequest::STATE_UNDER_REVIEW, TreasuryPaymentRequest::STATE_AWAITING_APPROVAL])
            ->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            $conditions[] = [
                'type' => TreasuryCashFlowAlert::TYPE_PENDING_APPROVAL,
                'severity' => TreasuryCashFlowAlert::SEVERITY_WARNING,
                'title' => 'Payment request awaiting approval',
                'detail' => ($request->client_reference ?: 'Request #' . $request->id) . ' — ' . number_format((float) ($request->amount_base ?? $request->amount_original ?? 0), 2) . ' ' . ($request->currency ?? ''),
                'source_type' => 'payment_request',
                'source_id' => $request->id,
            ];
        }

        // Delayed transfers: in transit for more than 3 days.
        if (Schema::hasTable('treasury_transfers')) {
            $transfers = TreasuryTransfer::where('created_by', $creatorId)
                ->where('state', TreasuryTransfer::STATE_IN_TRANSIT)
                ->where('updated_at', '<', $asOf->copy()->subDays(self::DELAYED_TRANSFER_DAYS)->endOfDay())
                ->with('operation')
                ->orderBy('id')
                ->get();

            foreach ($transfers as $transfer) {
                $conditions[] = [
                    'type' => TreasuryCashFlowAlert::TYPE_DELAYED_TRANSFER,
                    'severity' => TreasuryCashFlowAlert::SEVERITY_WARNING,
                    'title' => 'Delayed transfer in transit',
                    'detail' => ($transfer->reference() ?? 'Transfer #' . $transfer->id) . ' has been in transit since ' . Carbon::parse($transfer->updated_at)->toDateString() . '.',
                    'source_type' => 'transfer',
                    'source_id' => $transfer->id,
                ];
            }
        }

        // Overdue advances: money out, due date passed.
        $advances = TreasuryAdvance::where('created_by', $creatorId)
            ->whereIn('state', TreasuryAdvance::DISBURSED_STATES)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $asOf->toDateString())
            ->where('amount_outstanding', '>', 0)
            ->orderBy('due_date')
            ->get();

        foreach ($advances as $advance) {
            $conditions[] = [
                'type' => TreasuryCashFlowAlert::TYPE_OVERDUE_ADVANCE,
                'severity' => TreasuryCashFlowAlert::SEVERITY_WARNING,
                'title' => 'Overdue advance',
                'detail' => ($advance->recipient_name ?? 'Advance #' . $advance->id) . ' — ' . number_format((float) $advance->amount_outstanding, 2) . ' ' . $advance->currency . ' outstanding since ' . $advance->due_date . '.',
                'source_type' => 'advance',
                'source_id' => $advance->id,
            ];
        }

        // Sync failures: a statement sync cursor with a recent
        // recorded error (cleared by the next successful import).
        if (Schema::hasTable('treasury_statement_sync_cursors') && Schema::hasColumn('treasury_statement_sync_cursors', 'last_error_at')) {
            $cursors = TreasuryStatementSyncCursor::query()
                ->join('bank_accounts', 'bank_accounts.id', '=', 'treasury_statement_sync_cursors.bank_account_id')
                ->where('bank_accounts.created_by', $creatorId)
                ->whereNotNull('treasury_statement_sync_cursors.last_error_at')
                ->where('treasury_statement_sync_cursors.last_error_at', '>=', $asOf->copy()->subDays(self::SYNC_FAILURE_DAYS)->startOfDay())
                ->select('treasury_statement_sync_cursors.*')
                ->get();

            foreach ($cursors as $cursor) {
                $conditions[] = [
                    'type' => TreasuryCashFlowAlert::TYPE_SYNC_FAILURE,
                    'severity' => TreasuryCashFlowAlert::SEVERITY_CRITICAL,
                    'title' => 'Statement sync failed',
                    'detail' => 'Source "' . $cursor->source_key . '" failed at ' . Carbon::parse($cursor->last_error_at)->format('Y-m-d H:i') . ': ' . (string) ($cursor->last_error ?? ''),
                    'source_type' => 'sync_cursor',
                    'source_id' => $cursor->id,
                ];
            }
        }

        return $conditions;
    }

    protected function conditionKey(array $condition): string
    {
        return $condition['type'] . '|' . (string) $condition['source_type'] . '|' . (string) ($condition['source_id'] ?? '');
    }
}
