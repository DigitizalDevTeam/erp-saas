<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the Documentos list (the "Documentos" tab of the client's
 * file): one expense document, one category, one cost centre.
 *
 * The file's three "NÃO MEXER" columns are computed, not stored:
 *  - MÊS      — month of doc_date (1-12).
 *  - GRUPO P&L — the category's rule group (see rule->group).
 *  - VALOR NET — BASE when present and non-zero, else TOTAL.
 *
 * Doc types:
 *  - invoice, bank_transfer, manual — counted in costs.
 *  - credit_note — NEGATIVE amounts; counted in costs, so it reduces
 *    them (a credit note is an adjustment/void of the original).
 *  - quotation — stored but EXCLUDED from costs (it is not a cost yet).
 * A cost with NO document is a row with an empty doc number + type
 * "manual" (salaries, IRPS, VAT, employee payments...). If Danilo
 * later wants a separate screen for those, the data model already
 * supports it — only the UI would be new.
 *
 * Company scoping follows this app's convention: `created_by` holds
 * the creator/company user id (see User::creatorId()).
 */
class ManagementDocument extends Model
{
    protected $fillable = [
        'created_by',
        'doc_date',
        'supplier_nif',
        'supplier_name',
        'doc_number',
        'doc_type',
        'base',
        'iva',
        'retention',
        'total',
        'status',
        'bank_account',
        'payment_method',
        'management_rule_id',
        'management_cost_centre_id',
        'notes',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'base' => 'decimal:2',
        'iva' => 'decimal:2',
        'retention' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public const DOC_TYPES = [
        'invoice',
        'credit_note',
        'quotation',
        'bank_transfer',
        'manual',
    ];

    /** Types that count toward costs (quotations are stored, not counted). */
    public const COSTING_TYPES = [
        'invoice',
        'credit_note',
        'bank_transfer',
        'manual',
    ];

    public const STATUSES = [
        'Conciliado',
        'Pendente',
        'Anulado',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ManagementRule::class, 'management_rule_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(ManagementCostCentre::class, 'management_cost_centre_id');
    }

    /** MÊS — the month of the document date (1-12). */
    public function month(): int
    {
        return (int) $this->doc_date->format('n');
    }

    /** GRUPO P&L — the P&L group of the document's category. */
    public function plGroup(): ?string
    {
        return $this->rule?->group;
    }

    /**
     * VALOR NET — BASE when present and non-zero, else TOTAL.
     * (The file's rule: =SE(BASE<>"";BASE;TOTAL) — an empty BASE falls
     * back to TOTAL; a BASE of 0 also falls back, matching the file's
     * behaviour on the 3 rows with an empty BASE.)
     */
    public function netValue(): float
    {
        $base = $this->base !== null ? (float) $this->base : null;

        return $base !== null && $base != 0.0 ? $base : (float) $this->total;
    }

    public function countsTowardCosts(): bool
    {
        return in_array($this->doc_type, self::COSTING_TYPES, true);
    }

    public function isManual(): bool
    {
        return $this->doc_type === 'manual';
    }
}
