<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 5 — one cash movement inside a session (plan §5).
 *
 * Types: inflow / outflow / float_top_up / withdrawal / handover.
 * Method: cash / card / mobile. Only cash movements write treasury
 * entries (balance movements on the register account); card and
 * mobile movements are session-local records shown separately from
 * physical cash. A handover writes no entry here — its entry comes
 * from the Phase 4 transfer that moved the money (transfer_id).
 *
 * Money is DECIMAL(20,4) (RF04).
 */
class TreasuryCashMovement extends Model
{
    public const TYPE_INFLOW = 'inflow';
    public const TYPE_OUTFLOW = 'outflow';
    public const TYPE_FLOAT_TOP_UP = 'float_top_up';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_HANDOVER = 'handover';

    public const TYPES = [
        self::TYPE_INFLOW,
        self::TYPE_OUTFLOW,
        self::TYPE_FLOAT_TOP_UP,
        self::TYPE_WITHDRAWAL,
        self::TYPE_HANDOVER,
    ];

    /** Types recorded through recordMovement() (handover has its own call). */
    public const RECORDABLE_TYPES = [
        self::TYPE_INFLOW,
        self::TYPE_OUTFLOW,
        self::TYPE_FLOAT_TOP_UP,
        self::TYPE_WITHDRAWAL,
    ];

    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_MOBILE = 'mobile';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_CARD,
        self::METHOD_MOBILE,
    ];

    protected $fillable = [
        'cash_session_id',
        'created_by',
        'recorded_by',
        'type',
        'method',
        'amount',
        'amount_base',
        'reference',
        'reason',
        'transfer_id',
        'entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'amount_base' => 'decimal:4',
    ];

    public function session()
    {
        return $this->belongsTo(TreasuryCashSession::class, 'cash_session_id');
    }

    public function transfer()
    {
        return $this->belongsTo(TreasuryTransfer::class, 'transfer_id');
    }

    public function entry()
    {
        return $this->belongsTo(TreasuryEntry::class, 'entry_id');
    }

    /** True for the types that add physical cash to the till. */
    public function addsCash(): bool
    {
        return in_array($this->type, [self::TYPE_INFLOW, self::TYPE_FLOAT_TOP_UP], true);
    }

    /** True for the types that remove physical cash from the till. */
    public function removesCash(): bool
    {
        return in_array($this->type, [self::TYPE_OUTFLOW, self::TYPE_WITHDRAWAL, self::TYPE_HANDOVER], true);
    }
}
