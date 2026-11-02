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
    // Not a money-in method: Internal Consumption is stock used inside the
    // restaurant, not a sale. Payments with this method must never count
    // towards cash, card, credit or sales totals, or expected cash.
    const METHOD_INTERNAL = 'internal';

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

    public static function labelForMethod(?string $method): string
    {
        $method = strtolower(trim((string) $method));
        if ($method === '') {
            return '';
        }

        return match ($method) {
            self::METHOD_CASH => __('pos.payment_cash'),
            self::METHOD_CREDIT => __('pos.payment_credit'),
            'card' => __('pos.payment_card'),
            'visa' => __('pos.payment_visa'),
            self::METHOD_MPESA => __('pos.payment_mpesa'),
            self::METHOD_BANK_TRANSFER, 'bank transfer' => __('pos.payment_bank_transfer'),
            'cheque', 'check' => __('pos.payment_cheque'),
            self::METHOD_MIXED => __('pos.mixed_payment'),
            self::METHOD_INTERNAL => __('pos.payment_internal'),
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    /**
     * Method a document type forces, regardless of what was submitted.
     * Internal Consumption forces 'internal' (never cash/card/cheque);
     * Credit Sale forces 'credit'. Returns null when the caller may keep
     * the requested method.
     */
    public static function forcedMethodForDocumentType(?string $documentType, ?string $requestedMethod = null): ?string
    {
        $type = strtolower(trim((string) $documentType));
        if ($type === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
            return self::METHOD_INTERNAL;
        }
        if ($type === Pos::DOCUMENT_TYPE_CREDIT_SALE) {
            return self::METHOD_CREDIT;
        }

        return null;
    }

    public static function isInternalConsumptionDocument(?string $documentType): bool
    {
        return strtolower(trim((string) $documentType)) === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION;
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function bankAccount()
    {
        return $this->hasOne('App\Models\BankAccount', 'id', 'account_id');
    }
}
