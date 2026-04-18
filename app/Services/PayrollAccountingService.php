<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\PaySlip;
use App\Models\Plan;
use App\Models\SaturationDeduction;
use App\Models\User;
use App\Models\Utility;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;

class PayrollAccountingService
{
    public function __construct(
        private JournalEntryPoster $journalEntryPoster
    ) {
    }

    /**
     * HRM + Accounting: plan must have both HRM and Account modules enabled.
     */
    public static function isAccountingPayrollEnabledForCreator(int $creatorId): bool
    {
        $owner = User::find($creatorId);
        if (! $owner || ! $owner->plan) {
            return false;
        }
        $plan = Plan::find($owner->plan);

        return $plan && (int) $plan->hrm === 1 && (int) $plan->account === 1;
    }

    /**
     * Posts a balanced journal for a payslip payment: expense debits, IRPS / INSS / loan / deduction credits,
     * net salaries payable, clearing payable, and bank credit. Updates bank balance via JournalEntryPoster.
     *
     * @throws \InvalidArgumentException When prerequisites are missing or amounts do not balance.
     */
    public function postPaymentJournal(PaySlip $payslip, Employee $employee): JournalEntry
    {
        $creatorId = (int) $payslip->created_by;
        if (! self::isAccountingPayrollEnabledForCreator($creatorId)) {
            throw new \InvalidArgumentException(__('Accounting payroll is not enabled for this company.'));
        }

        if ((int) ($payslip->payroll_journal_id ?? 0) > 0) {
            $existing = JournalEntry::find($payslip->payroll_journal_id);
            if ($existing) {
                return $existing;
            }
        }

        $err = $this->validatePrerequisites($payslip, $employee);
        if ($err !== null) {
            throw new \InvalidArgumentException($err);
        }

        $settings = Utility::settings();
        $irpsAcc = (int) ($settings['payroll_irps_liability_account'] ?? 0);
        $inssAcc = (int) ($settings['payroll_inss_employee_liability_account'] ?? 0);
        $payableAcc = (int) ($settings['payroll_salaries_payable_account'] ?? 0);

        $date = Carbon::parse($payslip->salary_month . '-01')->endOfMonth()->format('Y-m-d');
        $basicSalary = (float) $payslip->basic_salary;

        $lines = [];

        $pushDr = function (int $accountId, float $amount, string $desc) use (&$lines) {
            $amount = round($amount, 2);
            if ($accountId <= 0 || $amount <= 0) {
                return;
            }
            $lines[] = [
                'account' => $accountId,
                'debit' => $amount,
                'credit' => 0,
                'description' => $desc,
            ];
        };

        $pushCr = function (int $accountId, float $amount, string $desc) use (&$lines) {
            $amount = round($amount, 2);
            if ($accountId <= 0 || $amount <= 0) {
                return;
            }
            $lines[] = [
                'account' => $accountId,
                'debit' => 0,
                'credit' => $amount,
                'description' => $desc,
            ];
        };

        $salaryChart = (int) ($employee->salary_chart_account_id ?? 0);
        $pushDr($salaryChart, $basicSalary, __('Payroll — basic salary'));

        $this->appendJsonDebitLines($payslip->allowance, 'allowance', $basicSalary, $pushDr, __('Payroll — allowance'));
        $this->appendJsonDebitLines($payslip->commission, 'commission', $basicSalary, $pushDr, __('Payroll — commission'));
        $this->appendJsonDebitLines($payslip->other_payment, 'other_payment', $basicSalary, $pushDr, __('Payroll — other payment'));
        $this->appendJsonDebitLines($payslip->overtime, 'overtime', $basicSalary, $pushDr, __('Payroll — overtime'));

        $this->appendJsonCreditLines($payslip->loan, 'loan', $basicSalary, $pushCr, __('Payroll — loan recovery'));
        $this->appendJsonCreditLines($payslip->saturation_deduction, 'saturation_deduction', $basicSalary, $pushCr, __('Payroll — deduction'));

        $irps = round((float) $payslip->irps_amount, 2);
        $inssEmp = round((float) $payslip->inss_employee_amount, 2);
        $net = round((float) $payslip->net_payble, 2);

        $pushCr($irpsAcc, $irps, __('IRPS withheld'));
        $pushCr($inssAcc, $inssEmp, __('INSS (employee) withheld'));
        // Net salary: credit salaries payable (liability to employee), then debit payable and debit bank (this app decreases bank on debit).
        $pushCr($payableAcc, $net, __('Net salaries payable'));
        $pushDr($payableAcc, $net, __('Clear net salaries payable'));

        $bank = BankAccount::find((int) $employee->account);
        $bankChart = $bank ? (int) $bank->chart_account_id : 0;
        if ($net > 0 && $bankChart <= 0) {
            throw new \InvalidArgumentException(__('Employee payment bank account must have a chart of account mapped.'));
        }

        // Cash payment: debit bank (this codebase decreases bank opening balance on debit).
        $pushDr($bankChart, $net, __('Bank — net salary payment'));

        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $ln) {
            $sumDr += (float) ($ln['debit'] ?? 0);
            $sumCr += (float) ($ln['credit'] ?? 0);
        }

        if (round($sumDr, 2) !== round($sumCr, 2)) {
            $diff = round($sumCr - $sumDr, 2);
            if ($salaryChart <= 0) {
                throw new \InvalidArgumentException(
                    __('Payroll journal does not balance (:dr debit vs :cr credit). Set employee salary expense account.', ['dr' => $sumDr, 'cr' => $sumCr])
                );
            }
            if ($diff > 0) {
                $lines[] = [
                    'account' => $salaryChart,
                    'debit' => $diff,
                    'credit' => 0,
                    'description' => __('Payroll — rounding / mapping adjustment'),
                ];
            } elseif ($diff < 0) {
                $lines[] = [
                    'account' => $salaryChart,
                    'debit' => 0,
                    'credit' => abs($diff),
                    'description' => __('Payroll — rounding / mapping adjustment'),
                ];
            }
        }

        $ref = 'PAY-PS-' . $payslip->id;
        $desc = __('Payroll payment :month — :name', [
            'month' => (string) $payslip->salary_month,
            'name' => (string) $employee->name,
        ]);

        $journal = $this->journalEntryPoster->post(
            $creatorId,
            $date,
            $ref,
            $desc,
            $lines,
            null
        );

        $payslip->payroll_journal_id = $journal->id;
        $payslip->save();

        return $journal;
    }

    public function validatePrerequisites(PaySlip $payslip, Employee $employee): ?string
    {
        $settings = Utility::settings();
        $irpsAcc = (int) ($settings['payroll_irps_liability_account'] ?? 0);
        $inssAcc = (int) ($settings['payroll_inss_employee_liability_account'] ?? 0);
        $payableAcc = (int) ($settings['payroll_salaries_payable_account'] ?? 0);

        $irps = round((float) $payslip->irps_amount, 2);
        $inssEmp = round((float) $payslip->inss_employee_amount, 2);
        $net = round((float) $payslip->net_payble, 2);

        if ($irps > 0 && $irpsAcc <= 0) {
            return __('Set IRPS liability account in System Settings (Payroll accounting).');
        }
        if ($inssEmp > 0 && $inssAcc <= 0) {
            return __('Set INSS (employee) liability account in System Settings (Payroll accounting).');
        }
        if ($net > 0 && $payableAcc <= 0) {
            return __('Set salaries payable account in System Settings (Payroll accounting).');
        }

        $bank = BankAccount::find((int) $employee->account);
        if ($net > 0 && (! $bank || (int) $bank->chart_account_id <= 0)) {
            return __('Select a bank account with a chart of account for the employee to pay net salary.');
        }

        if ((float) $payslip->basic_salary > 0 && (int) ($employee->salary_chart_account_id ?? 0) <= 0) {
            return __('Set salary expense chart account on the employee record.');
        }

        $basicSalary = (float) $payslip->basic_salary;
        $jsonErr = $this->validateJsonAccounts($payslip, $basicSalary);
        if ($jsonErr !== null) {
            return $jsonErr;
        }

        return null;
    }

    private function validateJsonAccounts(PaySlip $payslip, float $basicSalary): ?string
    {
        $checkDebit = function (?string $json, string $module, string $label) use ($basicSalary) {
            if (empty($json)) {
                return null;
            }
            $rows = json_decode($json, true);
            if (! is_array($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $amt = $this->moduleAmount($row, $module, $basicSalary);
                if ($amt <= 0) {
                    continue;
                }
                $acc = (int) ($row['chart_account_id'] ?? 0);
                if ($acc <= 0) {
                    return __('Set chart of account for :label (earning line).', ['label' => $label]);
                }
            }

            return null;
        };

        $checkLoanSat = function (?string $json, string $module, string $label) use ($basicSalary) {
            if (empty($json)) {
                return null;
            }
            $rows = json_decode($json, true);
            if (! is_array($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $amt = $this->moduleAmount($row, $module, $basicSalary);
                if ($amt <= 0) {
                    continue;
                }
                $acc = (int) ($row['chart_account_id'] ?? 0);
                if (($row['id'] ?? 0) > 0 && $module === 'loan') {
                    $loan = Loan::find((int) $row['id']);
                    if ($loan && (int) ($loan->chart_account_id ?? 0) > 0) {
                        $acc = (int) $loan->chart_account_id;
                    }
                }
                if (($row['id'] ?? 0) > 0 && $module === 'saturation_deduction') {
                    $sat = SaturationDeduction::find((int) $row['id']);
                    if ($sat && (int) ($sat->chart_account_id ?? 0) > 0) {
                        $acc = (int) $sat->chart_account_id;
                    }
                }
                if ($acc <= 0) {
                    return __('Set chart of account for :label (deduction line).', ['label' => $label]);
                }
            }

            return null;
        };

        foreach (
            [
                [$payslip->allowance, 'allowance', __('allowance')],
                [$payslip->commission, 'commission', __('commission')],
                [$payslip->other_payment, 'other_payment', __('other payment')],
                [$payslip->overtime, 'overtime', __('overtime')],
            ] as [$json, $mod, $lbl]
        ) {
            $e = $checkDebit($json, $mod, $lbl);
            if ($e) {
                return $e;
            }
        }

        foreach (
            [
                [$payslip->loan, 'loan', __('loan')],
                [$payslip->saturation_deduction, 'saturation_deduction', __('deduction')],
            ] as [$json, $mod, $lbl]
        ) {
            $e = $checkLoanSat($json, $mod, $lbl);
            if ($e) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param  callable(int, float, string): void  $pushDr
     */
    private function appendJsonDebitLines(?string $json, string $module, float $basicSalary, callable $pushDr, string $refLabel): void
    {
        if (empty($json)) {
            return;
        }
        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $accountId = (int) ($row['chart_account_id'] ?? 0);
            $subId = (int) ($row['id'] ?? 0);
            $amount = $this->moduleAmount($row, $module, $basicSalary);
            if ($accountId <= 0 || $amount <= 0) {
                continue;
            }
            $pushDr($accountId, $amount, $refLabel . ' #' . $subId);
        }
    }

    /**
     * @param  callable(int, float, string): void  $pushCr
     */
    private function appendJsonCreditLines(?string $json, string $module, float $basicSalary, callable $pushCr, string $refLabel): void
    {
        if (empty($json)) {
            return;
        }
        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $accountId = (int) ($row['chart_account_id'] ?? 0);
            if ($accountId <= 0 && ($row['id'] ?? 0) > 0 && $module === 'loan') {
                $loan = Loan::find((int) $row['id']);
                $accountId = $loan ? (int) ($loan->chart_account_id ?? 0) : 0;
            }
            if ($accountId <= 0 && ($row['id'] ?? 0) > 0 && $module === 'saturation_deduction') {
                $sat = SaturationDeduction::find((int) $row['id']);
                $accountId = $sat ? (int) ($sat->chart_account_id ?? 0) : 0;
            }
            $subId = (int) ($row['id'] ?? 0);
            $amount = $this->moduleAmount($row, $module, $basicSalary);
            if ($accountId <= 0 || $amount <= 0) {
                continue;
            }
            $pushCr($accountId, $amount, $refLabel . ' #' . $subId);
        }
    }

    private function moduleAmount(array $row, string $module, float $basicSalary): float
    {
        if ($module === 'overtime') {
            return round((float) ($row['number_of_days'] ?? 0) * (float) ($row['hours'] ?? 0) * (float) ($row['rate'] ?? 0), 2);
        }

        $amount = (float) ($row['amount'] ?? 0);
        if (($row['type'] ?? 'fixed') === 'percentage') {
            return round($amount * $basicSalary / 100, 2);
        }

        return round($amount, 2);
    }
}
