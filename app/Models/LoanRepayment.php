<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanRepayment extends Model
{
    protected $fillable = [
        'loan_id',
        'payslip_id',
        'salary_month',
        'principal_paid',
        'interest_paid',
        'total_paid',
        'balance_after_principal',
        'created_by',
    ];

    protected $casts = [
        'principal_paid' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'balance_after_principal' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function payslip()
    {
        return $this->belongsTo(PaySlip::class);
    }
}
