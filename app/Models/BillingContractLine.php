<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 1 — billing contract line.
 *
 * One row per contract line. amount = quantity * unit_price -
 * discount_amount, computed by BillingContractService with bcmath.
 * Tax comes from the Tax model (tax_id), never hard-coded.
 */
class BillingContractLine extends Model
{
    protected $fillable = [
        'contract_id', 'product_id', 'description', 'quantity',
        'unit_price', 'discount_amount', 'tax_id', 'chart_account_id',
        'cost_centre_id', 'amount', 'created_by',
    ];

    public function contract()
    {
        return $this->belongsTo(BillingContract::class, 'contract_id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}
