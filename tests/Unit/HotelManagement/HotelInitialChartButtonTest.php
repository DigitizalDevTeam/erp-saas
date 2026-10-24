<?php

namespace Tests\Unit\HotelManagement;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountCsvImportService;
use App\Support\PayrollChartAccountOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Http\Controllers\HotelAccountingSettingsController;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Initial Chart of Account" button on /hotel/accounting-settings:
 * shows only when the company has no chart accounts, and creates the
 * initial (PGC) chart via ChartOfAccountCsvImportService on click.
 */
class HotelInitialChartButtonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('type')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('roles', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('chart_of_account_types', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_zh')->nullable();
            $table->string('name_es')->nullable();
            $table->string('name_pt_br')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('chart_of_account_sub_types', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_zh')->nullable();
            $table->string('name_es')->nullable();
            $table->string('name_pt_br')->nullable();
            $table->integer('type')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_zh')->nullable();
            $table->string('name_es')->nullable();
            $table->string('name_pt_br')->nullable();
            $table->string('code')->nullable();
            $table->integer('type')->nullable();
            $table->integer('sub_type')->nullable();
            $table->integer('parent')->default(0);
            $table->integer('parent_account_id')->nullable();
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_zh')->nullable();
            $table->text('description_es')->nullable();
            $table->text('description_pt_br')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('chart_of_account_parents', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_zh')->nullable();
            $table->string('name_es')->nullable();
            $table->string('name_pt_br')->nullable();
            $table->integer('sub_type')->nullable();
            $table->integer('type')->nullable();
            $table->integer('account')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('bank_name')->nullable();
            $table->string('holder_name')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('hotel_accounting_settings', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('chart_guest_ar_id')->nullable();
            $table->unsignedBigInteger('chart_room_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_restaurant_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_laundry_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_misc_revenue_id')->nullable();
            $table->unsignedBigInteger('chart_vat_payable_id')->nullable();
            $table->decimal('default_vat_rate_percent', 5, 2)->default(16);
            $table->boolean('pos_invoice_when_charge_to_room')->default(false);
            $table->boolean('require_minibar_scan_at_checkout')->default(false);
            $table->json('folio_payment_bank_map')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->name = 'Hotel Company';
        $user->email = 'hotel@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage hotel', 'guard_name' => 'web']);
        $user->givePermissionTo('manage hotel');
    }

    private function indexData(): array
    {
        $view = (new HotelAccountingSettingsController())->index();

        return $view->getData();
    }

    public function test_button_shows_when_company_has_no_chart(): void
    {
        $data = $this->indexData();

        $this->assertArrayHasKey('hasChartAccount', $data);
        $this->assertFalse($data['hasChartAccount']);
    }

    public function test_button_hides_once_a_chart_account_exists(): void
    {
        $type = ChartOfAccountType::create([
            'name' => 'Assets', 'name_pt_br' => 'Ativos', 'created_by' => 1,
        ]);
        ChartOfAccount::create([
            'name' => 'Cash', 'code' => '11', 'type' => $type->id,
            'sub_type' => 0, 'parent' => 0, 'is_enabled' => 1, 'created_by' => 1,
        ]);

        $data = $this->indexData();

        $this->assertTrue($data['hasChartAccount']);
    }

    public function test_view_renders_button_only_when_no_chart(): void
    {
        $blade = file_get_contents(base_path('Modules/HotelManagement/Resources/views/accounting_settings/index.blade.php'));

        $this->assertStringContainsString('@if(!$hasChartAccount)', $blade);
        $this->assertStringContainsString('Initial Chart of Account', $blade);
        $this->assertStringContainsString('hotel.accounting-settings.initial-chart', $blade);
    }

    public function test_initial_chart_refuses_when_chart_already_exists(): void
    {
        ChartOfAccount::create([
            'name' => 'Cash', 'code' => '11', 'type' => 0,
            'sub_type' => 0, 'parent' => 0, 'is_enabled' => 1, 'created_by' => 1,
        ]);

        $fake = new class extends ChartOfAccountCsvImportService {
            public bool $called = false;

            public function importForCompany(int $companyId, bool $skipIfCode11Exists = false): array
            {
                $this->called = true;

                return ['status' => 'imported', 'imported' => 528];
            }
        };

        $response = (new HotelAccountingSettingsController())->initialChart($fake);

        $this->assertFalse($fake->called);
        $this->assertStringContainsString('hotel/accounting-settings', $response->getTargetUrl());
    }

    public function test_initial_chart_creates_pgc_chart_the_dropdowns_can_use(): void
    {
        $result = app(ChartOfAccountCsvImportService::class)->importForCompany(1, true);

        $this->assertSame('imported', $result['status']);
        $this->assertGreaterThan(100, $result['imported']);

        // The hotel dropdown builders must now find each category.
        $this->assertNotEmpty(PayrollChartAccountOptions::listAssetsForCurrentUser('—')->except('')->all());
        $this->assertNotEmpty(PayrollChartAccountOptions::listLiabilitiesForCurrentUser('—')->except('')->all());
        $this->assertNotEmpty(PayrollChartAccountOptions::listIncomeForCurrentUser('—')->except('')->all());

        $this->assertTrue($this->indexData()['hasChartAccount']);
    }
}
