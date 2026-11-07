<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Treasury Phase 8 — one dated occurrence of a recurring rule
 * (plan §5, §6, §10, CA14).
 *
 * period_key is unique per rule + period, so a job run twice
 * creates one occurrence per rule and period. Flow: generate →
 * confirm → paid (or skip). An occurrence never executes a
 * payment by itself.
 */
class TreasuryRecurringOccurrence extends Model
{
    public const STATE_GENERATED = 'generated';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_PAID = 'paid';
    public const STATE_SKIPPED = 'skipped';

    public const STATES = [
        self::STATE_GENERATED,
        self::STATE_CONFIRMED,
        self::STATE_PAID,
        self::STATE_SKIPPED,
    ];

    protected $fillable = [
        'rule_id',
        'period_key',
        'scheduled_date',
        'amount',
        'amount_base',
        'currency',
        'direction',
        'state',
        'generated_at',
        'confirmed_by',
        'confirmed_at',
        'paid_at',
        'skipped_at',
        'skip_reason',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'generated_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TreasuryRecurringRule::class, 'rule_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, [self::STATE_GENERATED, self::STATE_CONFIRMED], true);
    }
}
