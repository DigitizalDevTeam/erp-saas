<?php

namespace Tests\Unit\Accounting;

use App\Http\Controllers\ChartOfAccountController;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountCsvImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Auto Setup Chart of Account" button on /chart-of-account:
 * shows only when the company has no chart accounts, and creates the
 * standard (PGC) chart via ChartOfAccountCsvImportService on click.
 * Refuses when a chart already exists, so it can never duplicate one.
 */
class ChartOfAccountAutoSetupButtonTest extends TestCase
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

        $user = new User();
        $user->name = 'Test Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage chart of account', 'guard_name' => 'web']);
        Permission::create(['name' => 'create chart of account', 'guard_name' => 'web']);
        $user->givePermissionTo('manage chart of account');
        $user->givePermissionTo('create chart of account');
    }

    private function indexData(): array
    {
        $view = (new ChartOfAccountController())->index(new Request());

        return $view->getData();
    }

    public function test_index_reports_no_chart_when_company_has_none(): void
    {
        $data = $this->indexData();

        $this->assertArrayHasKey('hasChartAccount', $data);
        $this->assertFalse($data['hasChartAccount']);
    }

    public function test_index_reports_chart_once_an_account_exists(): void
    {
        $type = ChartOfAccountType::create([
            'name' => 'Assets', 'name_pt_br' => 'Ativos', 'created_by' => 1,
        ]);
        ChartOfAccount::create([
            'name' => 'Cash', 'code' => '11', 'type' => $type->id,
            'sub_type' => 0, 'parent' => 0, 'is_enabled' => 1, 'created_by' => 1,
        ]);

        $this->assertTrue($this->indexData()['hasChartAccount']);
    }

    public function test_view_shows_auto_setup_button_only_when_no_chart(): void
    {
        $blade = file_get_contents(base_path('resources/views/chartOfAccount/index.blade.php'));

        $this->assertStringContainsString('@if (!$hasChartAccount)', $blade);
        $this->assertStringContainsString('Auto Setup Chart of Account', $blade);
        $this->assertStringContainsString('chart-of-account.auto-setup', $blade);
    }

    public function test_auto_setup_refuses_when_chart_already_exists(): void
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

        $response = (new ChartOfAccountController())->autoSetup($fake);

        $this->assertFalse($fake->called);
        $this->assertStringContainsString('chart-of-account', $response->getTargetUrl());
        $this->assertSame(1, ChartOfAccount::where('created_by', 1)->count());
    }

    public function test_auto_setup_denies_user_without_create_permission(): void
    {
        $user = Auth::user();
        $user->revokePermissionTo('create chart of account');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $fake = new class extends ChartOfAccountCsvImportService {
            public bool $called = false;

            public function importForCompany(int $companyId, bool $skipIfCode11Exists = false): array
            {
                $this->called = true;

                return ['status' => 'imported', 'imported' => 528];
            }
        };

        (new ChartOfAccountController())->autoSetup($fake);

        $this->assertFalse($fake->called);
        $this->assertSame(0, ChartOfAccount::where('created_by', 1)->count());
    }

    public function test_auto_setup_creates_the_standard_chart(): void
    {
        $response = (new ChartOfAccountController())->autoSetup(app(ChartOfAccountCsvImportService::class));

        $this->assertStringContainsString('chart-of-account', $response->getTargetUrl());
        $this->assertGreaterThan(100, ChartOfAccount::where('created_by', 1)->count());
        $this->assertTrue(ChartOfAccount::where('created_by', 1)->where('code', '11')->exists());
        $this->assertTrue($this->indexData()['hasChartAccount']);
    }

    public function test_auto_setup_twice_does_not_duplicate(): void
    {
        (new ChartOfAccountController())->autoSetup(app(ChartOfAccountCsvImportService::class));
        $countAfterFirst = ChartOfAccount::where('created_by', 1)->count();

        (new ChartOfAccountController())->autoSetup(app(ChartOfAccountCsvImportService::class));

        $this->assertSame($countAfterFirst, ChartOfAccount::where('created_by', 1)->count());
    }
}
