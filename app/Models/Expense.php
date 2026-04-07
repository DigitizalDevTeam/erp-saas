<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    public static function getStatuses()
    {
        return [
            self::STATUS_PENDING   => __('Pending'),
            self::STATUS_APPROVED  => __('Approve'),
            self::STATUS_REJECTED  => __('Reject'),
        ];
    }

    public function getStatusLabelAttribute()
    {
        return self::getStatuses()[$this->status] ?? $this->status;
    }

    protected $fillable = [
        'name',
        
        'date',
        'description',
        'amount',
        'attachment',
        'status',
        'user_id',
        'expense_category_id',
        'branch_id',
        'pos_shift_id',
        'payment_account_id',
        'chart_account_id',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function posShift()
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id', 'id');
    }

    public function paymentBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'payment_account_id');
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }
}
