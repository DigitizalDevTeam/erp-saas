<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\PaySlip;
use App\Services\PayrollTaxService;
use Illuminate\Console\Command;

/**
 * Recompute INSS / IRPS on payslips that are still unpaid.
 *
 * Payslips generated while a company's IRPS withholding table was
 * empty stored a 0.00 IRPS (and an overstated net). Once the table
 * is seeded, this command brings the unpaid slips back in line with
 * the same calculation the payslip form uses.
 *
 * Paid slips are deliberately left untouched: their money has moved,
 * and correcting them needs a human decision, not a batch rewrite.
 */
class RecalculateUnpaidPayslips extends Command
{
    protected $signature = 'payroll:recalculate-unpaid {--month= : Only recompute payslips for one month (YYYY-MM)}';

    protected $description = 'Recompute INSS and IRPS on unpaid payslips from the current withholding table.';

    public function handle(): int
    {
        $query = PaySlip::query()->where('status', 0);

        if ($this->option('month')) {
            $query->where('salary_month', $this->option('month'));
        }

        $recalculated = 0;
        $skipped = 0;

        foreach ($query->orderBy('salary_month')->orderBy('employee_id')->get() as $payslip) {
            $employee = Employee::find($payslip->employee_id);
            if (! $employee) {
                $skipped++;
                continue;
            }

            PayrollTaxService::recalculatePayslip($payslip, $employee);
            $payslip->save();
            $recalculated++;
        }

        $this->info("Recalculated {$recalculated} unpaid payslip(s), skipped {$skipped} (employee missing).");

        return self::SUCCESS;
    }
}
