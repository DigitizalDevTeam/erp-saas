<?php

namespace Tests\Unit\Payroll;

use App\Models\Allowance;
use App\Models\Commission;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\OtherPayment;
use App\Models\Overtime;
use App\Models\PaySlip;
use App\Models\SaturationDeduction;
use App\Services\IrpsModel22ReportService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;

class IrpsPayrollCalculationTest extends TestCase
{
    public function test_irps_base_uses_all_earnings_before_deductions(): void
    {
        $employee = new Employee();
        $employee->salary = 1000;

        $allowance = new Allowance();
        $allowance->type = 'fixed';
        $allowance->amount = 200;

        $commission = new Commission();
        $commission->type = 'percentage';
        $commission->amount = 10;

        $loan = new Loan();
        $loan->type = 'fixed';
        $loan->amount = 75;

        $saturationDeduction = new SaturationDeduction();
        $saturationDeduction->type = 'percentage';
        $saturationDeduction->amount = 5;

        $otherPayment = new OtherPayment();
        $otherPayment->type = 'fixed';
        $otherPayment->amount = 50;

        $overtime = new Overtime();
        $overtime->number_of_days = 2;
        $overtime->hours = 3;
        $overtime->rate = 10;

        $employee->setRelation('allowances', new EloquentCollection([$allowance]));
        $employee->setRelation('commissions', new EloquentCollection([$commission]));
        $employee->setRelation('loans', new EloquentCollection([$loan]));
        $employee->setRelation('saturationDeductions', new EloquentCollection([$saturationDeduction]));
        $employee->setRelation('otherPayments', new EloquentCollection([$otherPayment]));
        $employee->setRelation('overtimes', new EloquentCollection([$overtime]));

        $this->assertEquals(1285.0, $employee->get_net_salary('2026-04'));
        $this->assertEquals(1410.0, $employee->get_irps_base_salary('2026-04'));
    }

    public function test_model_22_breakdown_uses_earnings_snapshots_for_irps_base(): void
    {
        $payslip = new PaySlip();
        $payslip->basic_salary = 1000;
        $payslip->allowance = json_encode([
            ['type' => 'fixed', 'amount' => 200],
        ]);
        $payslip->commission = json_encode([
            ['type' => 'fixed', 'amount' => 100],
        ]);
        $payslip->other_payment = json_encode([
            ['type' => 'fixed', 'amount' => 50],
        ]);
        $payslip->overtime = json_encode([
            ['number_of_days' => 2, 'hours' => 3, 'rate' => 10],
        ]);
        $payslip->loan = json_encode([
            ['type' => 'fixed', 'amount' => 75],
        ]);
        $payslip->saturation_deduction = json_encode([
            ['type' => 'fixed', 'amount' => 50],
        ]);
        $payslip->inss_employee_amount = 30;
        $payslip->inss_employer_amount = 40;
        $payslip->irps_amount = 45;
        $payslip->net_payble = 500;

        $breakdown = IrpsModel22ReportService::breakdown($payslip, 0);

        $this->assertEquals(1410.0, $breakdown['gross_irps_base']);
        $this->assertEquals(1410.0, $breakdown['total_earnings_components']);
        $this->assertEquals(45.0, $breakdown['irps']);
        $this->assertEquals(200.0, $breakdown['total_deductions']);
    }
}
