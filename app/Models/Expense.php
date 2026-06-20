<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    const STATUS_PENDING = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_EXECUTED = 3;

    public static function getStatuses()
    {
        return [
            self::STATUS_PENDING   => __('Pending'),
            self::STATUS_APPROVED  => __('Approved'),
            self::STATUS_REJECTED  => __('Rejected'),
            self::STATUS_EXECUTED  => __('Executed'),
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
        'executed_at',
        'executed_by',
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
        'executed_at' => 'datetime',
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

    public function executedByUser()
    {
        return $this->belongsTo(User::class, 'executed_by', 'id');
    }

    public function isEditable(): bool
    {
        return in_array((int) $this->status, [self::STATUS_PENDING, self::STATUS_REJECTED, self::STATUS_EXECUTED], true);
    }

    public function isAccountingPosted(): bool
    {
        return in_array((int) $this->status, [self::STATUS_APPROVED, self::STATUS_EXECUTED], true);
    }

    public function scopeCashOutPosted($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_EXECUTED]);
    }
}
