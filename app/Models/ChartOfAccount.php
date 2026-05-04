<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    protected $fillable = [
        'name',
        'name_fr',
        'name_zh',
        'name_es',
        'name_pt_br',
        'code',
        'type',
        'sub_type',
        'parent',
        'parent_account_id',
        'is_enabled',
        'description',
        'description_fr',
        'description_zh',
        'description_es',
        'description_pt_br',
        'created_by',
    ];

    public function types()
    {
        return $this->hasOne('App\Models\ChartOfAccountType', 'id', 'type');
    }

    public function accounts()
    {
        return $this->hasOne('App\Models\JournalItem', 'account', 'id');
    }

    public function balance()
    {
        $journalItem         = JournalItem::select(\DB::raw('sum(credit) as totalCredit'),
            \DB::raw('sum(debit) as totalDebit'),
            \DB::raw('sum(credit) - sum(debit) as netAmount'))->where('account', $this->id);
        $journalItem         = $journalItem->first();
        $data['totalCredit'] = $journalItem->totalCredit;
        $data['totalDebit']  = $journalItem->totalDebit;
        $data['netAmount']   = $journalItem->netAmount;

        return $data;
    }

    public function subType()
    {
        return $this->hasOne('App\Models\ChartOfAccountSubType', 'id', 'sub_type');
    }

    public function parentAccount()
    {
        return $this->hasOne('App\Models\ChartOfAccountParent', 'id', 'parent');
    }

    /** Direct sub-accounts under this account (3rd level in hierarchy) */
    public function subChildren()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_account_id', 'id');
    }

    /** Parent account record when this account is under a sub-account */
    public function parentAccountRecord()
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_account_id', 'id');
    }

    /**
     * Display name for the current user's language — same logic as the Chart of Accounts index (English `name` as default).
     */
    public function labelForLocale(?string $lang = null): string
    {
        $lang = strtolower((string) ($lang ?? (auth()->check() ? (auth()->user()->lang ?? 'en') : 'en')));
        if (str_starts_with($lang, 'pt')) {
            $lang = 'pt';
        }

        $pick = match ($lang) {
            'fr' => $this->name_fr,
            'zh' => $this->name_zh,
            'es' => $this->name_es,
            'pt' => $this->name_pt_br,
            default => null,
        };

        if ($pick !== null && trim((string) $pick) !== '') {
            return trim((string) $pick);
        }

        return trim((string) ($this->name ?? ''));
    }
}
