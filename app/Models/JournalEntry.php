<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    protected $fillable = [
        'date',
        'reference',
        'description',
        'journal_id',
        'source',
        'status',
        'created_by',
        'accounting_period',
    ];

    /** How the journal was created: 'manual' (Journal Entry screen) or 'system' (JournalEntryPoster). */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYSTEM = 'system';

    public static function getStatuses()
    {
        return [
            self::STATUS_PENDING => __('Pending'),
            self::STATUS_APPROVED => __('Approve'),
            self::STATUS_REJECTED => __('Reject'),
        ];
    }

    public function getStatusLabelAttribute()
    {
        return self::getStatuses()[$this->status] ?? $this->status;
    }


    public function accounts()
    {
        return $this->hasmany('App\Models\JournalItem', 'journal', 'id');
    }

    public function totalCredit()
    {
        $total = 0;
        foreach ($this->accounts as $account) {
            $total += (float) $account->credit;
        }

        return round($total, 2);
    }

    public function totalDebit()
    {
        $total = 0;
        foreach ($this->accounts as $account) {
            $total += (float) $account->debit;
        }

        return round($total, 2);
    }


}
