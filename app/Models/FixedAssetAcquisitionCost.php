<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 1 — one cost component of an acquisition
 * (plan §5, spec §8).
 *
 * Capitalized Cost = Purchase Price + Transport + Installation
 * + Import Duties + Non Recoverable Taxes + Professional Fees
 * + Directly Attributable Costs − Discounts. Recoverable VAT
 * must NOT form part of asset cost: each component stores the
 * gross amount (VAT included) and the VAT inside it; the
 * recoverable part is subtracted by the cost builder, never
 * stored in the capitalized total. Every component keeps its
 * source-document reference.
 */
class FixedAssetAcquisitionCost extends Model
{
    public const TYPE_PURCHASE_PRICE = 'purchase_price';
    public const TYPE_TRANSPORT = 'transport';
    public const TYPE_INSTALLATION = 'installation';
    public const TYPE_IMPORT_DUTIES = 'import_duties';
    public const TYPE_NON_RECOVERABLE_TAXES = 'non_recoverable_taxes';
    public const TYPE_PROFESSIONAL_FEES = 'professional_fees';
    public const TYPE_DIRECTLY_ATTRIBUTABLE = 'directly_attributable';
    public const TYPE_DISCOUNT = 'discount';

    public const TYPES = [
        self::TYPE_PURCHASE_PRICE,
        self::TYPE_TRANSPORT,
        self::TYPE_INSTALLATION,
        self::TYPE_IMPORT_DUTIES,
        self::TYPE_NON_RECOVERABLE_TAXES,
        self::TYPE_PROFESSIONAL_FEES,
        self::TYPE_DIRECTLY_ATTRIBUTABLE,
        self::TYPE_DISCOUNT,
    ];

    /** Human-readable labels for the cost-builder form. */
    public static function labels(): array
    {
        return [
            self::TYPE_PURCHASE_PRICE => 'Purchase Price',
            self::TYPE_TRANSPORT => 'Transport',
            self::TYPE_INSTALLATION => 'Installation',
            self::TYPE_IMPORT_DUTIES => 'Import Duties',
            self::TYPE_NON_RECOVERABLE_TAXES => 'Non-Recoverable Taxes',
            self::TYPE_PROFESSIONAL_FEES => 'Professional Fees',
            self::TYPE_DIRECTLY_ATTRIBUTABLE => 'Directly Attributable Costs',
            self::TYPE_DISCOUNT => 'Discount',
        ];
    }

    protected $fillable = [
        'created_by',
        'acquisition_id',
        'component_type',
        'gross_amount',
        'vat_amount',
        'vat_recoverable',
        'currency',
        'exchange_rate',
        'amount_base',
        'source_document_reference',
        'notes',
    ];

    protected $casts = [
        'vat_recoverable' => 'boolean',
    ];

    /**
     * Signed contribution of this component to capitalized cost,
     * in the acquisition currency: gross minus recoverable VAT
     * (non-recoverable VAT stays in the cost); discounts are
     * negative. The base-currency twin is amount_base.
     */
    public function getContributionAttribute(): string
    {
        $net = bcsub((string) $this->gross_amount, $this->vat_recoverable ? (string) $this->vat_amount : '0', 4);

        return $this->component_type === self::TYPE_DISCOUNT ? bcmul($net, '-1', 4) : $net;
    }

    public function acquisition()
    {
        return $this->belongsTo(FixedAssetAcquisition::class, 'acquisition_id');
    }
}
