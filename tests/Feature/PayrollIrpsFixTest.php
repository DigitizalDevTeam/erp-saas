<?php

namespace Tests\Feature;

use App\Console\Commands\RecalculateUnpaidPayslips;
use App\Exports\PayslipExport;
use App\Models\Bill;
use App\Models\Employee;
use App\Models\IrpsWithholdingRow;
use App\Models\PaySlip;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Utility;
use App\Services\PayrollTaxService;
use Database\Seeders\IrpsWithholdingMoz2014Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Payroll IRPS fix (client feedback 2026-11-26).
 *
 * The IRPS withholding table was empty for every company, so every
 * payslip computed a 0.00 IRPS and the company bore the workers'
 * tax. Covers: the 50,000 / 0-dependents case (IRPS 5,225, net
 * 43,275), the empty-table zero, the seeder covering every payroll
 * company, the unpaid-payslip recompute command, the total-earnings
 * fix (basic salary was missing), the payslip export columns, and
 * the bill <-> purchase link.
 *
 * User 1 = Company (manage irps withholding), employee Jose is
 * id 1 with salary 50,000, 0 dependents, created_by 1.
 */
class PayrollIrpsFixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage irps withholding', 'guard_name' => 'web']);
        Permission::create(['name' => 'manage report', 'guard_name' => 'web']);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 2, 'email_verified_at' => now()],
        ]);
        User::findOrFail(1)->givePermissionTo(['manage irps withholding', 'manage report']);

        DB::table('settings')->insert([
            ['name' => 'payroll_tax_burden_mode', 'value' => 'worker_pays', 'created_by' => 1],
        ]);

        $this->seedJose();
    }

    protected function tearDown(): void
    {
        foreach (['departments', 'branch_user', 'branches', 'projects', 'chart_of_accounts', 'email_templates', 'ch_messages', 'languages', 'plans', 'purchases', 'bills', 'pay_slips', 'overtimes', 'other_payments', 'saturation_deductions', 'loans', 'commissions', 'allowances', 'employees', 'irps_withholding_rows', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_irps_for_50000_with_no_dependents_is_5225(): void
    {
        IrpsWithholdingRow::seedMoz2014ForCompany(1);
        $employee = Employee::findOrFail(1);

        $this->assertSame(5225.0, PayrollTaxService::calculateIrps($employee, 50000.0));

        $inss = PayrollTaxService::calculateInss($employee, 50000.0);
        $this->assertSame(1500.0, $inss['employee']);
        $this->assertSame(2000.0, $inss['employer']);
    }

    public function test_recalculate_payslip_gives_5225_irps_and_43275_net(): void
    {
        IrpsWithholdingRow::seedMoz2014ForCompany(1);
        $payslip = PaySlip::findOrFail(1);

        PayrollTaxService::recalculatePayslip($payslip, Employee::findOrFail(1));
        $payslip->save();

        $this->assertSame(1500.0, (float) $payslip->inss_employee_amount);
        $this->assertSame(2000.0, (float) $payslip->inss_employer_amount);
        $this->assertSame(5225.0, (float) $payslip->irps_amount);
        $this->assertSame(43275.0, (float) $payslip->net_payble);
    }

    public function test_irps_is_zero_when_the_withholding_table_is_empty(): void
    {
        // The reported bug: no rows at all means a 0.00 IRPS for everyone.
        $this->assertSame(0, IrpsWithholdingRow::count());

        $employee = Employee::findOrFail(1);
        $this->assertSame(0.0, PayrollTaxService::calculateIrps($employee, 50000.0));
    }

    public function test_seeder_covers_every_company_with_employees(): void
    {
        Employee::create([
            'name' => 'Other Employee', 'employee_id' => '00002', 'salary' => 30000,
            'dependents' => 0, 'created_by' => 2,
        ]);

        (new IrpsWithholdingMoz2014Seeder())->run();

        $this->assertSame(10, IrpsWithholdingRow::where('created_by', 1)->count());
        $this->assertSame(10, IrpsWithholdingRow::where('created_by', 2)->count());
        $this->assertSame(5225.0, PayrollTaxService::calculateIrps(Employee::findOrFail(1), 50000.0));
    }

    public function test_recalculate_unpaid_command_fixes_unpaid_and_skips_paid(): void
    {
        IrpsWithholdingRow::seedMoz2014ForCompany(1);

        $paid = PaySlip::create([
            'employee_id' => 1, 'basic_salary' => 50000, 'salary_month' => '2026-08',
            'status' => 1, 'allowance' => '[]', 'commission' => '[]', 'loan' => '[]',
            'saturation_deduction' => '[]', 'other_payment' => '[]', 'overtime' => '[]',
            'inss_employee_amount' => 1500, 'inss_employer_amount' => 2000,
            'irps_amount' => 0, 'net_payble' => 48500, 'created_by' => 1,
        ]);

        $this->artisan(RecalculateUnpaidPayslips::class)->assertExitCode(0);

        $unpaid = PaySlip::findOrFail(1);
        $this->assertSame(5225.0, (float) $unpaid->irps_amount);
        $this->assertSame(43275.0, (float) $unpaid->net_payble);

        // Paid slips are history: untouched.
        $this->assertSame(0.0, (float) $paid->fresh()->irps_amount);
        $this->assertSame(48500.0, (float) $paid->fresh()->net_payble);
    }

    public function test_total_earning_includes_basic_salary(): void
    {
        IrpsWithholdingRow::seedMoz2014ForCompany(1);
        $payslip = PaySlip::findOrFail(1);
        PayrollTaxService::recalculatePayslip($payslip, Employee::findOrFail(1));
        $payslip->save();

        $detail = Utility::employeePayslipDetail(1, '2026-09');

        $this->assertSame(50000.0, (float) $detail['totalEarning']);
        $this->assertSame(6725.0, (float) $detail['totalDeduction']);
    }

    public function test_payslip_export_has_inss_and_irps_columns(): void
    {
        $this->assertContains('INSS (Employee)', (new PayslipExport((object) ['filter_month' => '09', 'filter_year' => '2026']))->headings());
        $this->assertContains('IRPS', (new PayslipExport((object) ['filter_month' => '09', 'filter_year' => '2026']))->headings());

        $this->actingAs(User::findOrFail(1));
        $rows = (new PayslipExport((object) ['filter_month' => '09', 'filter_year' => '2026']))->collection();
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('inss_employee', $rows->first());
        $this->assertArrayHasKey('irps', $rows->first());
    }

    public function test_bill_links_to_its_purchase_and_purchase_lists_its_bills(): void
    {
        $purchase = Purchase::create([
            'purchase_id' => 3, 'vender_id' => 1, 'status' => 1, 'created_by' => 1,
        ]);
        $bill = Bill::create([
            'bill_id' => 2, 'vender_id' => 1, 'purchase_id' => $purchase->id,
            'status' => 3, 'bill_date' => '2026-11-16', 'due_date' => '2026-11-16',
            'type' => 'Bill', 'user_type' => 'vendor', 'created_by' => 1,
        ]);

        $this->assertSame($purchase->id, $bill->fresh()->purchase->id);
        $this->assertSame(3, (int) $bill->fresh()->purchase->purchase_id);
        $this->assertTrue(Bill::where('purchase_id', $purchase->id)->exists());
    }

    public function test_company_pays_inss_mode_keeps_irps_but_moves_inss_to_employer(): void
    {
        $employee = $this->seedCompanyWithMode(2, 'company_pays_inss');
        $payslip = $this->seedPayslipFor($employee, 2);

        PayrollTaxService::recalculatePayslip($payslip, $employee);
        $payslip->save();

        // Worker still pays IRPS; the company pays both INSS shares.
        $this->assertSame(0.0, (float) $payslip->inss_employee_amount);
        $this->assertSame(3500.0, (float) $payslip->inss_employer_amount);
        $this->assertSame(5225.0, (float) $payslip->irps_amount);
        $this->assertSame(44775.0, (float) $payslip->net_payble);
    }

    public function test_company_pays_all_mode_absorbs_irps_and_inss(): void
    {
        // NOTE: created_by=3, not 2 — Utility caches settings per company id in
        // a static, so the company_pays_inss test's rows for id 2 would leak
        // into this test in the same PHP process.
        $employee = $this->seedCompanyWithMode(3, 'company_pays_all');
        $payslip = $this->seedPayslipFor($employee, 3);

        PayrollTaxService::recalculatePayslip($payslip, $employee);
        $payslip->save();

        $this->assertSame(0.0, (float) $payslip->inss_employee_amount);
        $this->assertSame(3500.0, (float) $payslip->inss_employer_amount);
        $this->assertSame(0.0, (float) $payslip->irps_amount);
        $this->assertSame(50000.0, (float) $payslip->net_payble);
    }

    public function test_payroll_report_shows_inss_and_irps_totals(): void
    {
        IrpsWithholdingRow::seedMoz2014ForCompany(1);
        $payslip = PaySlip::findOrFail(1);
        PayrollTaxService::recalculatePayslip($payslip, Employee::findOrFail(1));
        $payslip->save();

        $response = $this->actingAs(User::findOrFail(1))->get(
            route('report.payroll', ['type' => 'monthly', 'month' => '2026-09'])
        );

        $response->assertOk();
        $response->assertViewHas('filterData', function ($filterData) {
            return (float) $filterData['totalInssEmployee'] === 1500.0
                && (float) $filterData['totalIrps'] === 5225.0
                && (float) $filterData['totalNetSalary'] === 43275.0;
        });
        // The rendered sheet carries the two new columns.
        $response->assertSee('INSS');
        $response->assertSee('IRPS');
    }

    public function test_irps_withholding_store_creates_a_rate_row(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->post(route('irps-withholding.store'), [
            'salary_from' => 32750, 'salary_to' => 60749.99,
            'base_dep0' => 1775, 'base_dep1' => 1725, 'base_dep2' => 1700,
            'base_dep3' => 1675, 'base_dep4plus' => 1625, 'coefficient' => 0.20,
        ]);

        $response->assertRedirect(route('irps-withholding.index'));
        $this->assertSame(1, IrpsWithholdingRow::where('created_by', 1)->where('salary_from', 32750)->count());
    }

    public function test_irps_withholding_store_rejects_bad_bracket(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->post(route('irps-withholding.store'), [
            'salary_from' => 60749.99, 'salary_to' => 32750, 'coefficient' => 0.20,
        ]);

        $response->assertRedirect();
        $this->assertSame(0, IrpsWithholdingRow::count());
    }

    public function test_irps_withholding_index_is_scoped_to_the_company(): void
    {
        IrpsWithholdingRow::seedMoz2014ForCompany(1);
        IrpsWithholdingRow::seedMoz2014ForCompany(2);

        $response = $this->actingAs(User::findOrFail(1))->get(route('irps-withholding.index'));

        $response->assertOk();
        $response->assertViewHas('rows', function ($rows) {
            return $rows->count() === 10 && $rows->every(fn ($row) => (int) $row->created_by === 1);
        });
    }

    private function seedCompanyWithMode(int $createdBy, string $mode): Employee
    {
        DB::table('settings')->insert([
            ['name' => 'payroll_tax_burden_mode', 'value' => $mode, 'created_by' => $createdBy],
        ]);
        IrpsWithholdingRow::seedMoz2014ForCompany($createdBy);

        return Employee::create([
            'name' => 'Mode Employee', 'employee_id' => '0000' . $createdBy, 'salary' => 50000,
            'dependents' => 0, 'created_by' => $createdBy,
        ]);
    }

    private function seedPayslipFor(Employee $employee, int $createdBy): PaySlip
    {
        return PaySlip::create([
            'employee_id' => $employee->id, 'basic_salary' => 50000, 'salary_month' => '2026-09',
            'status' => 0, 'allowance' => '[]', 'commission' => '[]', 'loan' => '[]',
            'saturation_deduction' => '[]', 'other_payment' => '[]', 'overtime' => '[]',
            'inss_employee_amount' => 0, 'inss_employer_amount' => 0,
            'irps_amount' => 0, 'net_payble' => 50000, 'created_by' => $createdBy,
        ]);
    }

    private function seedJose(): void
    {
        Employee::create([
            'name' => 'Jose Inacio Leitao', 'employee_id' => '00001', 'salary' => 50000,
            'dependents' => 0, 'inss_employer_pays_all' => 0, 'created_by' => 1,
        ]);

        PaySlip::create([
            'employee_id' => 1, 'basic_salary' => 50000, 'salary_month' => '2026-09',
            'status' => 0, 'allowance' => '[]', 'commission' => '[]', 'loan' => '[]',
            'saturation_deduction' => '[]', 'other_payment' => '[]', 'overtime' => '[]',
            'inss_employee_amount' => 1500, 'inss_employer_amount' => 2000,
            'irps_amount' => 0, 'net_payble' => 48500, 'created_by' => 1,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function ($table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('employees', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('employee_id')->nullable();
            $table->decimal('salary', 15, 2)->default(0);
            $table->integer('dependents')->default(0);
            $table->integer('inss_employer_pays_all')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        foreach (['allowances', 'commissions', 'loans', 'saturation_deductions', 'other_payments'] as $component) {
            Schema::create($component, function ($table) {
                $table->bigIncrements('id');
                $table->integer('employee_id')->nullable();
                $table->string('type')->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                $table->timestamps();
            });
        }
        Schema::create('overtimes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('employee_id')->nullable();
            $table->integer('number_of_days')->default(0);
            $table->integer('hours')->default(0);
            $table->decimal('rate', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('pay_slips', function ($table) {
            $table->bigIncrements('id');
            $table->integer('employee_id');
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->string('salary_month')->nullable();
            $table->integer('status')->default(0);
            $table->text('allowance')->nullable();
            $table->text('commission')->nullable();
            $table->text('loan')->nullable();
            $table->text('saturation_deduction')->nullable();
            $table->text('other_payment')->nullable();
            $table->text('overtime')->nullable();
            $table->decimal('inss_employee_amount', 15, 2)->default(0);
            $table->decimal('inss_employer_amount', 15, 2)->default(0);
            $table->decimal('irps_amount', 15, 2)->default(0);
            $table->decimal('net_payble', 15, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('irps_withholding_rows', function ($table) {
            $table->bigIncrements('id');
            $table->decimal('salary_from', 15, 2)->default(0);
            $table->decimal('salary_to', 15, 2)->nullable();
            $table->decimal('base_dep0', 15, 2)->nullable();
            $table->decimal('base_dep1', 15, 2)->nullable();
            $table->decimal('base_dep2', 15, 2)->nullable();
            $table->decimal('base_dep3', 15, 2)->nullable();
            $table->decimal('base_dep4plus', 15, 2)->nullable();
            $table->decimal('coefficient', 10, 4)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('purchases', function ($table) {
            $table->bigIncrements('id');
            $table->integer('purchase_id')->nullable();
            $table->integer('vender_id')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id')->nullable();
            $table->integer('vender_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->integer('status')->default(0);
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('type')->nullable();
            $table->string('user_type')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        // Tables the admin layout reads when a page is rendered.
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });
        Schema::create('email_templates', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('projects', function ($table) {
            $table->bigIncrements('id');
            $table->string('project_name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('departments', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
    }
}
