<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPayment extends Model
{
    const METHOD_CASH = 'cash';
    const METHOD_CARD = 'card';
    const METHOD_CREDIT = 'credit';
    const METHOD_MIXED = 'mixed';
    const METHOD_MPESA = 'mpesa';
    const METHOD_BANK_TRANSFER = 'bank_transfer';

    protected $fillable = [
        'pos_id',
        'branch_id',
        'payment_method',
        'credit_terms',
        'customer_id',
        'transaction_id',
        'date',
        'amount',
        'change_amount',
        'discount',
        'discount_amount',
        'tip',
        'notes',
        'created_by',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function bankAccount()
    {
        return $this->hasOne('App\Models\BankAccount', 'id', 'account_id');
    }
}
