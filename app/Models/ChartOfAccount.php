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

    /** Direct child accounts (any depth; use ChartOfAccountHierarchy for full tree). */
    public function children()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_account_id', 'id');
    }

    /** @deprecated Use children() — kept for backward compatibility */
    public function subChildren()
    {
        return $this->children();
    }

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

        if (trim((string) ($this->name ?? '')) !== '') {
            return trim((string) $this->name);
        }

        // Never render a blank account description when the account only has
        // a translated name (e.g. Portuguese-only chart accounts).
        foreach ([$this->name_pt_br, $this->name_fr, $this->name_es, $this->name_zh] as $translated) {
            if ($translated !== null && trim((string) $translated) !== '') {
                return trim((string) $translated);
            }
        }

        return '';
    }

    /**
     * "code - name" dropdown label in the given (or current user's)
     * language — the locale-aware replacement for the raw SQL
     * CONCAT(code, ' - ', name) builders.
     */
    public function codeNameForLocale(?string $lang = null): string
    {
        return trim((string) ($this->code ?? '')) . ' - ' . $this->labelForLocale($lang);
    }
}
