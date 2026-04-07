<?php

namespace App\Models;

use App\Services\EmployeeLoanScheduleService;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    public const SCHEDULE_LEGACY = 'legacy';

    public const SCHEDULE_INSTALLMENT = 'installment';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'employee_id',
        'loan_option',
        'title',
        'amount',
        'type',
        'start_date',
        'end_date',
        'reason',
        'created_by',
        'schedule_mode',
        'principal_amount',
        'outstanding_principal',
        'annual_interest_rate',
        'term_months',
        'installments_paid',
        'first_deduction_month',
        'monthly_installment',
        'loan_status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'outstanding_principal' => 'decimal:2',
        'annual_interest_rate' => 'decimal:4',
        'monthly_installment' => 'decimal:2',
    ];

    public static $Loantypes = [
        'fixed' => 'Fixed',
        'percentage' => 'Percentage',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function loanOption()
    {
        return $this->belongsTo(LoanOption::class, 'loan_option');
    }

    public function repayments()
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function isInstallment(): bool
    {
        return $this->schedule_mode === self::SCHEDULE_INSTALLMENT;
    }

    public function isLegacy(): bool
    {
        return empty($this->schedule_mode) || $this->schedule_mode === self::SCHEDULE_LEGACY;
    }

    /**
     * Approximate monthly impact on gross-to-net (payslip estimate / HR summary).
     *
     * @param  string|null  $forPayrollMonthYm  Payroll month Y-m; installment loans deduct 0 before first_deduction_month.
     */
    public function estimatedMonthlyPayrollImpact(float $basicSalary, ?string $forPayrollMonthYm = null): float
    {
        $monthYm = $forPayrollMonthYm ?? date('Y-m');

        if ($this->isInstallment()) {
            if ($this->loan_status !== self::STATUS_ACTIVE) {
                return 0.0;
            }
            if (! empty($this->first_deduction_month) && strcmp($monthYm, $this->first_deduction_month) < 0) {
                return 0.0;
            }

            return (float) $this->monthly_installment;
        }

        if (($this->type ?? '') === 'percentage') {
            return round((float) $this->amount * $basicSalary / 100, 2);
        }

        return (float) $this->amount;
    }

    /**
     * One loan row for payslip JSON (snapshot). Uses reducing-balance EMI for installment loans.
     */
    public function toPayslipSnapshotObject(string $salaryMonthYm, float $basicSalary): object
    {
        if ($this->isInstallment()) {
            $b = EmployeeLoanScheduleService::computeInstallmentBreakdown($this, $salaryMonthYm);

            return (object) [
                'id' => $this->id,
                'employee_id' => $this->employee_id,
                'loan_option' => $this->loan_option,
                'title' => $this->title,
                'reason' => $this->reason,
                'type' => 'fixed',
                'amount' => $b['total'],
                'schedule_mode' => self::SCHEDULE_INSTALLMENT,
                'principal_component' => $b['principal'],
                'interest_component' => $b['interest'],
                'outstanding_before' => $b['outstanding_before'],
                'outstanding_after' => $b['outstanding_after'],
            ];
        }

        return (object) [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'loan_option' => $this->loan_option,
            'title' => $this->title,
            'reason' => $this->reason,
            'type' => $this->type ?? 'fixed',
            'amount' => (float) $this->amount,
            'schedule_mode' => self::SCHEDULE_LEGACY,
        ];
    }
}
