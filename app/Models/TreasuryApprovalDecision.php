<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 2 — approval decisions (plan §5, §6).
 *
 * Append-only history: every decision on a step is kept, including
 * decisions later invalidated by a change to the approved data
 * (CA05) — the step status shows they no longer count, the rows
 * themselves are never deleted.
 */
class TreasuryApprovalDecision extends Model
{
    public const DECISION_APPROVE = 'approve';
    public const DECISION_REJECT = 'reject';
    public const DECISION_RETURN = 'return';

    public const DECISIONS = [
        self::DECISION_APPROVE,
        self::DECISION_REJECT,
        self::DECISION_RETURN,
    ];

    protected $fillable = [
        'payment_request_id',
        'approval_step_id',
        'actor_id',
        'decision',
        'reason',
        'substitute_user_id',
        'substitute_expiry',
        'decided_at',
    ];

    protected $casts = [
        'substitute_expiry' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(TreasuryPaymentRequest::class, 'payment_request_id');
    }

    public function step()
    {
        return $this->belongsTo(TreasuryApprovalStep::class, 'approval_step_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function decidedAsSubstitute(): bool
    {
        return $this->substitute_user_id !== null;
    }
}
