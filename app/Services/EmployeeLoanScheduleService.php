<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\PaySlip;
use Illuminate\Support\Facades\DB;

/**
 * Employee term loans: EMI (reducing balance), repayments tied to payslip month, idempotent postings.
 */
class EmployeeLoanScheduleService
{
    public static function calculateEmi(float $principal, float $annualInterestPercent, int $termMonths): float
    {
        $termMonths = max(1, $termMonths);
        if ($principal <= 0) {
            return 0.0;
        }
        $r = ($annualInterestPercent / 100.0) / 12.0;
        if ($r <= 0.000000001) {
            return round($principal / $termMonths, 2);
        }
        $pow = pow(1 + $r, $termMonths);

        return round($principal * $r * $pow / ($pow - 1), 2);
    }

    /**
     * @return array{total: float, principal: float, interest: float, outstanding_before: float, outstanding_after: float}
     */
    public static function computeInstallmentBreakdown(Loan $loan, string $salaryMonthYm): array
    {
        $zero = [
            'total' => 0.0,
            'principal' => 0.0,
            'interest' => 0.0,
            'outstanding_before' => (float) $loan->outstanding_principal,
            'outstanding_after' => (float) $loan->outstanding_principal,
        ];

        $existing = LoanRepayment::where('loan_id', $loan->id)->where('salary_month', $salaryMonthYm)->first();
        if ($existing) {
            $after = (float) $existing->balance_after_principal;
            $principalPaid = (float) $existing->principal_paid;

            return [
                'total' => (float) $existing->total_paid,
                'principal' => $principalPaid,
                'interest' => (float) $existing->interest_paid,
                'outstanding_before' => round($after + $principalPaid, 2),
                'outstanding_after' => $after,
            ];
        }

        if ($loan->schedule_mode !== Loan::SCHEDULE_INSTALLMENT) {
            return $zero;
        }
        if ($loan->loan_status !== Loan::STATUS_ACTIVE) {
            return $zero;
        }

        if (! empty($loan->first_deduction_month) && strcmp($salaryMonthYm, $loan->first_deduction_month) < 0) {
            return $zero;
        }

        $O = round((float) $loan->outstanding_principal, 2);
        if ($O <= 0) {
            return $zero;
        }

        $emi = round((float) $loan->monthly_installment, 2);
        if ($emi <= 0) {
            return $zero;
        }

        $annual = (float) $loan->annual_interest_rate;
        $r = ($annual / 100.0) / 12.0;
        $interest = round($O * $r, 2);
        $principalPart = round($emi - $interest, 2);

        if ($principalPart > $O) {
            $principalPart = $O;
        }
        if ($principalPart < 0) {
            $principalPart = 0;
        }

        $total = round($principalPart + $interest, 2);

        if ($total <= 0) {
            return $zero;
        }

        $after = round($O - $principalPart, 2);

        return [
            'total' => $total,
            'principal' => $principalPart,
            'interest' => $interest,
            'outstanding_before' => $O,
            'outstanding_after' => max(0, $after),
        ];
    }

    public static function recordRepaymentsForPayslip(PaySlip $payslip): void
    {
        if (empty($payslip->employee_id) || empty($payslip->salary_month)) {
            return;
        }

        $loans = Loan::where('employee_id', $payslip->employee_id)
            ->where('created_by', $payslip->created_by)
            ->get();

        foreach ($loans as $loan) {
            if (! $loan->isInstallment()) {
                continue;
            }

            $b = self::computeInstallmentBreakdown($loan, $payslip->salary_month);
            if ($b['total'] <= 0) {
                continue;
            }

            if (LoanRepayment::where('loan_id', $loan->id)->where('salary_month', $payslip->salary_month)->exists()) {
                continue;
            }

            DB::transaction(function () use ($loan, $payslip, $b) {
                $rep = new LoanRepayment();
                $rep->loan_id = $loan->id;
                $rep->payslip_id = $payslip->id;
                $rep->salary_month = $payslip->salary_month;
                $rep->principal_paid = $b['principal'];
                $rep->interest_paid = $b['interest'];
                $rep->total_paid = $b['total'];
                $rep->balance_after_principal = $b['outstanding_after'];
                $rep->created_by = $payslip->created_by;
                $rep->save();

                $loan->outstanding_principal = $b['outstanding_after'];
                $loan->installments_paid = (int) $loan->installments_paid + 1;
                $loan->amount = $loan->monthly_installment;
                if ((float) $loan->outstanding_principal <= 0.009) {
                    $loan->outstanding_principal = 0;
                    $loan->loan_status = Loan::STATUS_COMPLETED;
                }
                $loan->save();
            });
        }
    }

    public static function reverseRepaymentsForPayslip(PaySlip $payslip): void
    {
        $rows = LoanRepayment::where('payslip_id', $payslip->id)->get();
        foreach ($rows as $row) {
            DB::transaction(function () use ($row) {
                $loan = Loan::find($row->loan_id);
                if ($loan && $loan->isInstallment()) {
                    $loan->outstanding_principal = round((float) $loan->outstanding_principal + (float) $row->principal_paid, 2);
                    $loan->installments_paid = max(0, (int) $loan->installments_paid - 1);
                    if ($loan->loan_status === Loan::STATUS_COMPLETED) {
                        $loan->loan_status = Loan::STATUS_ACTIVE;
                    }
                    $loan->save();
                }
                $row->delete();
            });
        }
    }

    public static function ensureRepaymentsForPayslip(PaySlip $payslip): void
    {
        self::recordRepaymentsForPayslip($payslip);
    }
}
