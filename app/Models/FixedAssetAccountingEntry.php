<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 1 — audit bridge asset transaction →
 * journal (plan §5, §7).
 *
 * Every asset journal (capitalization now, depreciation and
 * disposal in later phases) links back to its transaction
 * through this table, so the register always reconciles to
 * the GL.
 */
class FixedAssetAccountingEntry extends Model
{
    public const TYPE_CAPITALIZATION = 'capitalization';
    public const TYPE_DEPRECIATION = 'depreciation';
    public const TYPE_DISPOSAL = 'disposal';
    public const TYPE_REVALUATION = 'revaluation';
    public const TYPE_IMPAIRMENT = 'impairment';

    protected $fillable = [
        'created_by',
        'asset_id',
        'transaction_type',
        'transaction_id',
        'journal_id',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }
}
