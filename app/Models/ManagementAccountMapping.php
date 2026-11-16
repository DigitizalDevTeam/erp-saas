<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One chart-of-accounts account mapped to a management P&L group
 * (+ subgroup) for one company.
 *
 * A mapped account's MANUAL journal postings (source = 'manual',
 * status = approved) feed the management P&L cost lines — that is
 * how a cost with no document reaches the P&L (salaries, IRPS, VAT,
 * employee payments...). System-posted journals are never counted
 * here, so a bill that is also entered as a document can never
 * double-count. See ManagementJournalCosts.
 *
 * Company scoping follows this app's convention: `created_by` holds
 * the creator/company user id (see User::creatorId()).
 */
class ManagementAccountMapping extends Model
{
    protected $fillable = [
        'created_by',
        'chart_account_id',
        'group',
        'subgroup',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    /** Display label for the mapped account, e.g. "632 - Remunerações". */
    public function accountLabel(): string
    {
        $account = $this->account;

        return $account
            ? trim("{$account->code} - {$account->name}")
            : "#{$this->chart_account_id}";
    }
}
