<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryRecurringOccurrence;
use App\Models\TreasuryRecurringRule;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Treasury Phase 8 — recurring commitments (plan §5, §6, §10,
 * CA14).
 *
 * A rule is a repeating commitment (monthly rent, quarterly
 * service fee, annual licence — or a repeating receipt). The
 * job generates occurrences on schedule; a job run twice
 * creates one occurrence per rule and period (CA14) — the
 * unique (rule_id, period_key) index is the second guard after
 * firstOrCreate.
 *
 * Changes apply to future UNCONFIRMED occurrences only:
 * editing a rule deletes its future generated occurrences and
 * regenerates them under the new version; confirmed, paid and
 * skipped occurrences keep their amount. Pausing or ending a
 * rule keeps earlier occurrences.
 *
 * Automatic execution is disabled by default — an occurrence
 * never creates a payment by itself (plan §6, §12). The flow
 * is generate → confirm → paid (or skip).
 *
 * Anchor day: scheduled dates anchor on the rule's due day
 * (or its start day), clamped to the month length — 31 Jan →
 * 28 Feb → 31 Mar.
 *
 * Money is DECIMAL(20,4), bcmath only, never float (RF04).
 * Company scope is creatorId() on every query (CA16).
 */
class TreasuryRecurringService
{
    /** How far ahead the job generates (and how far back it backfills). */
    public const GENERATE_AHEAD_DAYS = 90;
    public const GENERATE_BACKFILL_DAYS = 30;

    public function ruleForCompany(int $creatorId, int $ruleId): TreasuryRecurringRule
    {
        $rule = TreasuryRecurringRule::where('created_by', $creatorId)->find($ruleId);

        if (! $rule) {
            throw new TreasuryException('Recurring rule not found for this company.');
        }

        return $rule;
    }

    public function occurrenceForCompany(int $creatorId, int $occurrenceId): TreasuryRecurringOccurrence
    {
        // Occurrences carry no company column of their own — scope
        // through the rule they belong to (CA16).
        $occurrence = TreasuryRecurringOccurrence::whereHas('rule', function ($query) use ($creatorId) {
            $query->where('created_by', $creatorId);
        })->find($occurrenceId);

        if (! $occurrence) {
            throw new TreasuryException('Recurring occurrence not found for this company.');
        }

        return $occurrence;
    }

    // ------------------------------------------------------------------
    // Rules — create / edit / pause / end
    // ------------------------------------------------------------------

    public function createRule(int $creatorId, array $data, int $actorId): TreasuryRecurringRule
    {
        $validated = $this->validateRuleData($data, true);
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        return TreasuryRecurringRule::create(array_merge($validated, [
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'currency' => $baseCurrency,
            'amount_base' => $validated['amount'],
            'state' => TreasuryRecurringRule::STATE_ACTIVE,
            'version' => 1,
        ]));
    }

    /**
     * Edit a rule. Schedule-affecting changes delete the future
     * GENERATED occurrences and regenerate them under the new
     * version; confirmed, paid and skipped occurrences keep their
     * amount. Non-schedule changes (beneficiary, description,
     * category) leave occurrences alone.
     */
    public function updateRule(int $creatorId, int $ruleId, array $data, ?Carbon $asOf = null): TreasuryRecurringRule
    {
        $rule = $this->ruleForCompany($creatorId, $ruleId);

        if ($rule->state === TreasuryRecurringRule::STATE_ENDED) {
            throw new TreasuryException('An ended rule cannot be edited — create a new rule.');
        }

        $validated = $this->validateRuleData($data, false);
        $scheduleChanged = $this->scheduleAffects($rule, $validated);

        return DB::transaction(function () use ($rule, $validated, $scheduleChanged, $asOf) {
            $rule->fill($validated);
            $rule->amount_base = $validated['amount'] ?? $rule->amount_base;
            $rule->version = $rule->version + 1;
            $rule->save();

            if ($scheduleChanged) {
                $today = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
                TreasuryRecurringOccurrence::where('rule_id', $rule->id)
                    ->where('state', TreasuryRecurringOccurrence::STATE_GENERATED)
                    ->where('scheduled_date', '>=', $today->toDateString())
                    ->delete();

                $this->generateForRule($rule, $today);
            }

            return $rule->fresh();
        });
    }

    public function pauseRule(int $creatorId, int $ruleId): TreasuryRecurringRule
    {
        $rule = $this->ruleForCompany($creatorId, $ruleId);

        if ($rule->state !== TreasuryRecurringRule::STATE_ACTIVE) {
            throw new TreasuryException('Only an active rule can be paused.');
        }

        $rule->state = TreasuryRecurringRule::STATE_PAUSED;
        $rule->save();

        return $rule->fresh();
    }

    public function resumeRule(int $creatorId, int $ruleId): TreasuryRecurringRule
    {
        $rule = $this->ruleForCompany($creatorId, $ruleId);

        if ($rule->state !== TreasuryRecurringRule::STATE_PAUSED) {
            throw new TreasuryException('Only a paused rule can be resumed.');
        }

        $rule->state = TreasuryRecurringRule::STATE_ACTIVE;
        $rule->save();

        return $rule->fresh();
    }

    /** Ending a rule keeps every earlier occurrence. */
    public function endRule(int $creatorId, int $ruleId): TreasuryRecurringRule
    {
        $rule = $this->ruleForCompany($creatorId, $ruleId);

        if ($rule->state === TreasuryRecurringRule::STATE_ENDED) {
            throw new TreasuryException('This rule is already ended.');
        }

        $rule->state = TreasuryRecurringRule::STATE_ENDED;
        $rule->save();

        return $rule->fresh();
    }

    // ------------------------------------------------------------------
    // Generation (CA14)
    // ------------------------------------------------------------------

    /**
     * Generate occurrences for every active rule of the company,
     * up to $until (today + 90 days by default). Idempotent: a
     * second run creates nothing new (CA14).
     *
     * Returns ['generated' => n, 'skipped' => n].
     */
    public function generateOccurrences(int $creatorId, ?Carbon $until = null, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
        $until = $until ? $until->copy()->startOfDay() : $asOf->copy()->addDays(self::GENERATE_AHEAD_DAYS);

        $generated = 0;
        $skipped = 0;

        $rules = TreasuryRecurringRule::where('created_by', $creatorId)
            ->where('state', TreasuryRecurringRule::STATE_ACTIVE)
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $result = $this->generateForRule($rule, $asOf, $until);
            $generated += $result['generated'];
            $skipped += $result['skipped'];
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Generate one rule's occurrences in the window. The window
     * starts at the later of the rule start and 30 days ago (a
     * new rule backfills up to a month, never the whole history)
     * and ends at the earlier of the rule end and $until.
     */
    public function generateForRule(TreasuryRecurringRule $rule, Carbon $asOf, ?Carbon $until = null): array
    {
        $until = $until ? $until->copy()->startOfDay() : $asOf->copy()->addDays(self::GENERATE_AHEAD_DAYS);
        $windowStart = max(
            Carbon::parse($rule->start_date)->startOfDay()->timestamp,
            $asOf->copy()->subDays(self::GENERATE_BACKFILL_DAYS)->startOfDay()->timestamp
        );
        $windowStart = Carbon::createFromTimestamp($windowStart)->startOfDay();
        $windowEnd = $rule->end_date !== null
            ? min(Carbon::parse($rule->end_date)->startOfDay()->timestamp, $until->timestamp)
            : $until->timestamp;
        $windowEnd = Carbon::createFromTimestamp($windowEnd)->startOfDay();

        if ($windowStart->greaterThan($windowEnd)) {
            return ['generated' => 0, 'skipped' => 0];
        }

        $generated = 0;
        $skipped = 0;

        foreach ($this->scheduledDates($rule, $windowStart, $windowEnd) as $date) {
            $key = $this->periodKey($rule, $date);

            try {
                $created = TreasuryRecurringOccurrence::firstOrCreate(
                    ['rule_id' => $rule->id, 'period_key' => $key],
                    [
                        'scheduled_date' => $date->toDateString(),
                        'amount' => $rule->amount,
                        'amount_base' => $rule->amount_base,
                        'currency' => $rule->currency,
                        'direction' => $rule->direction,
                        'state' => TreasuryRecurringOccurrence::STATE_GENERATED,
                        'generated_at' => now(),
                        'created_by' => $rule->created_by,
                    ]
                );
            } catch (QueryException) {
                // Lost a race with a concurrent run — the unique
                // index already holds this period (CA14).
                $skipped++;
                continue;
            }

            if ($created->wasRecentlyCreated) {
                $generated++;
            } else {
                $skipped++;
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Scheduled dates for a rule inside [$from, $to], anchored on
     * the rule's due day (or start day), clamped to the month
     * length. Custom intervals step from the start date.
     *
     * @return Carbon[]
     */
    public function scheduledDates(TreasuryRecurringRule $rule, Carbon $from, Carbon $to): array
    {
        $start = Carbon::parse($rule->start_date)->startOfDay();
        $anchorDay = (int) ($rule->due_day ?? $start->day);
        $dates = [];

        $add = function (Carbon $date) use (&$dates, $from, $to) {
            if ($date->greaterThanOrEqualTo($from) && $date->lessThanOrEqualTo($to)) {
                $dates[] = $date->copy()->startOfDay();
            }
        };

        switch ($rule->frequency) {
            case TreasuryRecurringRule::FREQUENCY_MONTHLY:
            case TreasuryRecurringRule::FREQUENCY_QUARTERLY:
            case TreasuryRecurringRule::FREQUENCY_SEMI_ANNUAL:
            case TreasuryRecurringRule::FREQUENCY_ANNUAL:
                $step = match ($rule->frequency) {
                    TreasuryRecurringRule::FREQUENCY_MONTHLY => 1,
                    TreasuryRecurringRule::FREQUENCY_QUARTERLY => 3,
                    TreasuryRecurringRule::FREQUENCY_SEMI_ANNUAL => 6,
                    default => 12,
                };
                $cursor = $start->copy()->startOfMonth();
                while ($cursor->lessThanOrEqualTo($to)) {
                    $day = min($anchorDay, $cursor->daysInMonth);
                    $candidate = $cursor->copy()->day($day);
                    if ($candidate->greaterThanOrEqualTo($start)) {
                        $add($candidate);
                    }
                    $cursor->addMonthsNoOverflow($step)->startOfMonth();
                }
                break;

            case TreasuryRecurringRule::FREQUENCY_CUSTOM:
                $interval = max(1, (int) ($rule->custom_interval ?? 1));
                $cursor = $start->copy();
                $guard = 0;
                while ($cursor->lessThanOrEqualTo($to) && $guard < 3700) {
                    $guard++;
                    $add($cursor);
                    $cursor = match ($rule->custom_unit) {
                        'weeks' => $cursor->copy()->addWeeks($interval),
                        'months' => $cursor->copy()->addMonthsNoOverflow($interval),
                        'years' => $cursor->copy()->addYearsNoOverflow($interval),
                        default => $cursor->copy()->addDays($interval),
                    };
                }
                break;

            default:
                throw new TreasuryException('Unknown recurring frequency: ' . $rule->frequency);
        }

        usort($dates, fn ($a, $b) => $a->timestamp <=> $b->timestamp);

        return $dates;
    }

    /** Deterministic period key per rule + scheduled date. */
    public function periodKey(TreasuryRecurringRule $rule, Carbon $date): string
    {
        return match ($rule->frequency) {
            TreasuryRecurringRule::FREQUENCY_MONTHLY => $date->format('Y-m'),
            TreasuryRecurringRule::FREQUENCY_QUARTERLY => $date->format('Y') . '-Q' . (int) ceil(((int) $date->format('m')) / 3),
            TreasuryRecurringRule::FREQUENCY_SEMI_ANNUAL => $date->format('Y') . '-H' . (((int) $date->format('m')) <= 6 ? 1 : 2),
            TreasuryRecurringRule::FREQUENCY_ANNUAL => $date->format('Y'),
            default => $date->format('Y-m-d'),
        };
    }

    // ------------------------------------------------------------------
    // Occurrence flow — generate → confirm → paid (or skip)
    // ------------------------------------------------------------------

    /**
     * Confirm a generated occurrence. When the rule needs
     * approval, the confirmer must differ from the rule's
     * creator — no self-confirm (segregation of duties).
     */
    public function confirmOccurrence(int $creatorId, int $occurrenceId, int $actorId): TreasuryRecurringOccurrence
    {
        $occurrence = $this->occurrenceForCompany($creatorId, $occurrenceId);

        if ($occurrence->state !== TreasuryRecurringOccurrence::STATE_GENERATED) {
            throw new TreasuryException('Only a generated occurrence can be confirmed.');
        }

        $rule = $occurrence->rule;
        if ($rule->approval_required && (int) $rule->created_by === $actorId) {
            throw new TreasuryException('You cannot confirm an occurrence of your own rule — another user must approve it.');
        }

        $occurrence->state = TreasuryRecurringOccurrence::STATE_CONFIRMED;
        $occurrence->confirmed_by = $actorId;
        $occurrence->confirmed_at = now();
        $occurrence->save();

        return $occurrence->fresh();
    }

    /** Mark a generated or confirmed occurrence as paid. */
    public function markPaid(int $creatorId, int $occurrenceId): TreasuryRecurringOccurrence
    {
        $occurrence = $this->occurrenceForCompany($creatorId, $occurrenceId);

        if (! in_array($occurrence->state, [TreasuryRecurringOccurrence::STATE_GENERATED, TreasuryRecurringOccurrence::STATE_CONFIRMED], true)) {
            throw new TreasuryException('Only a generated or confirmed occurrence can be marked paid.');
        }

        $occurrence->state = TreasuryRecurringOccurrence::STATE_PAID;
        $occurrence->paid_at = now();
        $occurrence->save();

        return $occurrence->fresh();
    }

    /** Skip a generated occurrence (it stays on record, it is not deleted). */
    public function skipOccurrence(int $creatorId, int $occurrenceId, ?string $reason = null): TreasuryRecurringOccurrence
    {
        $occurrence = $this->occurrenceForCompany($creatorId, $occurrenceId);

        if ($occurrence->state !== TreasuryRecurringOccurrence::STATE_GENERATED) {
            throw new TreasuryException('Only a generated occurrence can be skipped.');
        }

        $occurrence->state = TreasuryRecurringOccurrence::STATE_SKIPPED;
        $occurrence->skipped_at = now();
        $occurrence->skip_reason = $reason !== null ? substr(trim($reason), 0, 500) : null;
        $occurrence->save();

        return $occurrence->fresh();
    }

    /** Open occurrences due within $days days (for alerts + the cash-flow screen). */
    public function upcomingOccurrences(int $creatorId, int $days, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
        $until = $asOf->copy()->addDays(max(0, $days));

        return TreasuryRecurringOccurrence::whereHas('rule', function ($query) use ($creatorId) {
            $query->where('created_by', $creatorId);
        })
            ->where('state', TreasuryRecurringOccurrence::STATE_GENERATED)
            ->whereBetween('scheduled_date', [$asOf->toDateString(), $until->toDateString()])
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function scheduleAffects(TreasuryRecurringRule $rule, array $validated): bool
    {
        foreach (['amount', 'currency', 'direction', 'start_date', 'end_date', 'frequency', 'custom_interval', 'custom_unit', 'due_day', 'planned_account_id'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $old = $rule->{$field};
            $new = $validated[$field];
            if ($old instanceof \DateTimeInterface) {
                $old = $old->format('Y-m-d');
            }
            if ((string) ($old ?? '') !== (string) ($new ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** Validate + normalise rule input. $creating requires the schedule fields. */
    protected function validateRuleData(array $data, bool $creating): array
    {
        $out = [];

        if ($creating || array_key_exists('beneficiary', $data)) {
            $beneficiary = substr(trim((string) ($data['beneficiary'] ?? '')), 0, 150);
            if ($beneficiary === '') {
                throw new TreasuryException('Beneficiary is required.');
            }
            $out['beneficiary'] = $beneficiary;
        }

        if (array_key_exists('description', $data)) {
            $out['description'] = substr(trim((string) $data['description']), 0, 250) ?: null;
        }

        if (array_key_exists('category', $data)) {
            $out['category'] = substr(trim((string) $data['category']), 0, 100) ?: null;
        }

        if ($creating || array_key_exists('direction', $data)) {
            $direction = (string) ($data['direction'] ?? 'outflow');
            if (! in_array($direction, ['inflow', 'outflow'], true)) {
                throw new TreasuryException('Direction must be inflow or outflow.');
            }
            $out['direction'] = $direction;
        }

        if ($creating || array_key_exists('amount', $data)) {
            $amount = $this->decimalString($data['amount'] ?? '0', 'Amount');
            if (bccomp($amount, '0.0000', 4) <= 0) {
                throw new TreasuryException('Amount must be greater than zero.');
            }
            $out['amount'] = $amount;
        }

        if (array_key_exists('amount_type', $data)) {
            $out['amount_type'] = in_array($data['amount_type'], ['fixed', 'estimated'], true) ? $data['amount_type'] : 'fixed';
        }

        if ($creating || array_key_exists('start_date', $data)) {
            $out['start_date'] = $this->requiredDate($data['start_date'] ?? null, 'Start date');
        }

        if (array_key_exists('end_date', $data)) {
            $out['end_date'] = $data['end_date'] !== '' && $data['end_date'] !== null
                ? $this->requiredDate($data['end_date'], 'End date')
                : null;
        }

        if ($creating || array_key_exists('frequency', $data)) {
            $frequency = (string) ($data['frequency'] ?? '');
            if (! in_array($frequency, TreasuryRecurringRule::FREQUENCIES, true)) {
                throw new TreasuryException('Frequency must be monthly, quarterly, semi-annual, annual or custom.');
            }
            $out['frequency'] = $frequency;
        }

        if (array_key_exists('custom_interval', $data)) {
            $out['custom_interval'] = $data['custom_interval'] !== '' && $data['custom_interval'] !== null
                ? max(1, (int) $data['custom_interval'])
                : null;
        }

        if (array_key_exists('custom_unit', $data)) {
            $out['custom_unit'] = in_array($data['custom_unit'], TreasuryRecurringRule::CUSTOM_UNITS, true)
                ? $data['custom_unit']
                : null;
        }

        if (array_key_exists('due_day', $data)) {
            $out['due_day'] = $data['due_day'] !== '' && $data['due_day'] !== null
                ? min(31, max(1, (int) $data['due_day']))
                : null;
        }

        if (array_key_exists('planned_account_id', $data)) {
            $out['planned_account_id'] = $data['planned_account_id'] !== '' && $data['planned_account_id'] !== null
                ? (int) $data['planned_account_id']
                : null;
        }

        if (array_key_exists('approval_required', $data)) {
            $out['approval_required'] = ! empty($data['approval_required']);
        }

        // A custom rule needs its interval + unit.
        $frequency = $out['frequency'] ?? null;
        if ($frequency === TreasuryRecurringRule::FREQUENCY_CUSTOM) {
            $interval = $out['custom_interval'] ?? null;
            $unit = $out['custom_unit'] ?? null;
            if ($interval === null || $unit === null) {
                throw new TreasuryException('A custom rule needs an interval and a unit (days, weeks, months or years).');
            }
        }

        return $out;
    }

    protected function requiredDate(mixed $value, string $label): string
    {
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new TreasuryException($label . ' is not a valid date.');
        }

        return $date->toDateString();
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if (! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }
}
