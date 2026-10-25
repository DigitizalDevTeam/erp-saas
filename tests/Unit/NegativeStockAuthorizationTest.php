<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\NegativeStockAuthorizationService;
use App\Services\PosLineStockDeductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A below-zero POS sale needs a manager: managers signed in proceed
 * directly, everyone else must enter a manager password or POS PIN, and
 * the authorized line — and only that line — may deduct below zero.
 */
class NegativeStockAuthorizationTest extends TestCase
{
    private int $creatorId = 1;

    private User $manager;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('pos_pin')->nullable();
            $table->string('type')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->nullable();
            $table->integer('default_company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('model_has_roles', function ($table) {
            $table->unsignedInteger('role_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedInteger('permission_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->boolean('affect_stock')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('options_enabled')->default(false);
            $table->boolean('variants_enabled')->default(false);
            $table->boolean('batch_tracking')->default(false);
            $table->boolean('identifier_tracking')->default(false);
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_expiry_lots', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity_remaining', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->string('document_type')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->increments('id');
            $table->integer('pos_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('unit_subtotal', 15, 4)->nullable();
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 4)->default(0);
            $table->text('description')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->unsignedBigInteger('negative_stock_authorized_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_audit_logs', function ($table) {
            $table->increments('id');
            $table->integer('created_by')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('action')->nullable();
            $table->string('subject_type')->nullable();
            $table->integer('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->text('meta')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $company = new User();
        $company->name = 'Company';
        $company->email = 'company@example.com';
        $company->password = bcrypt('company-secret');
        $company->type = 'company';
        $company->created_by = 1;
        $company->save();
        $this->creatorId = (int) $company->id;

        $this->manager = new User();
        $this->manager->name = 'Manager';
        $this->manager->email = 'manager@example.com';
        $this->manager->password = bcrypt('manager-secret');
        $this->manager->pos_pin = '4321';
        $this->manager->type = 'staff';
        $this->manager->created_by = $this->creatorId;
        $this->manager->save();
        $this->manager->givePermissionTo(Permission::create(['name' => 'pos restaurant manager', 'guard_name' => 'web']));

        $this->waiter = new User();
        $this->waiter->name = 'Waiter';
        $this->waiter->email = 'waiter@example.com';
        $this->waiter->password = bcrypt('waiter-secret');
        $this->waiter->type = 'staff';
        $this->waiter->created_by = $this->creatorId;
        $this->waiter->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['pos_audit_logs', 'pos_products', 'pos', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'warehouses', 'product_recipes', 'product_services', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'roles', 'permissions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function makeProduct(array $overrides = []): ProductService
    {
        $product = new ProductService();
        $product->name = 'Coffee Beans';
        $product->type = 'product';
        $product->quantity = 5;
        $product->affect_stock = true;
        $product->created_by = $this->creatorId;
        foreach ($overrides as $key => $value) {
            $product->$key = $value;
        }
        $product->save();

        return $product;
    }

    public function test_manager_signed_in_authorizes_directly(): void
    {
        Auth::login($this->manager);

        $this->assertTrue(NegativeStockAuthorizationService::canAuthorizeDirectly($this->manager));
        $authorizer = NegativeStockAuthorizationService::authorizerForRequest(new Request(), $this->creatorId);
        $this->assertNotNull($authorizer);
        $this->assertEquals($this->manager->id, $authorizer->id);
    }

    public function test_waiter_needs_manager_password_or_pin(): void
    {
        Auth::login($this->waiter);

        $this->assertFalse(NegativeStockAuthorizationService::canAuthorizeDirectly($this->waiter));
        $this->assertNull(NegativeStockAuthorizationService::authorizerForRequest(new Request(), $this->creatorId));
        $this->assertNull(NegativeStockAuthorizationService::authorizerForRequest(Request::create('/', 'POST', ['manager_password' => 'wrong']), $this->creatorId));
        $this->assertNull(NegativeStockAuthorizationService::authorizerForRequest(Request::create('/', 'POST', ['manager_password' => 'waiter-secret']), $this->creatorId));

        $byPassword = NegativeStockAuthorizationService::authorizerForRequest(Request::create('/', 'POST', ['manager_password' => 'manager-secret']), $this->creatorId);
        $this->assertNotNull($byPassword);
        $this->assertEquals($this->manager->id, $byPassword->id);

        $byPin = NegativeStockAuthorizationService::authorizerForRequest(Request::create('/', 'POST', ['manager_password' => '4321']), $this->creatorId);
        $this->assertNotNull($byPin);
        $this->assertEquals($this->manager->id, $byPin->id);
    }

    public function test_batch_and_serial_products_can_never_go_negative(): void
    {
        $plain = $this->makeProduct();
        $batch = $this->makeProduct(['batch_tracking' => true]);
        $serial = $this->makeProduct(['identifier_tracking' => true]);

        $this->assertFalse(NegativeStockAuthorizationService::neverNegative($plain));
        $this->assertTrue(NegativeStockAuthorizationService::neverNegative($batch));
        $this->assertTrue(NegativeStockAuthorizationService::neverNegative($serial));
    }

    public function test_grant_is_usable_then_expires(): void
    {
        app('session')->start();
        NegativeStockAuthorizationService::storeGrant(7, null, (int) $this->manager->id);

        $grant = NegativeStockAuthorizationService::grantFor(7, null);
        $this->assertNotNull($grant);
        $this->assertEquals((int) $this->manager->id, (int) $grant['manager_id']);
        $this->assertNull(NegativeStockAuthorizationService::grantFor(8, null));

        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertNull(NegativeStockAuthorizationService::grantFor(7, null));
    }

    public function test_migration_adds_authorizer_column(): void
    {
        Schema::table('pos_products', function ($table) {
            $table->dropColumn('negative_stock_authorized_by');
        });
        $this->assertFalse(Schema::hasColumn('pos_products', 'negative_stock_authorized_by'));

        $migration = require base_path('database/migrations/2026_10_25_233000_add_negative_stock_authorized_by_to_pos_products.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('pos_products', 'negative_stock_authorized_by'));
    }

    private function stockScenario(?int $authorizedBy): array
    {
        $company = User::where('type', 'company')->first();
        Auth::login($company);

        $product = $this->makeProduct();
        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = $this->creatorId;
        $w->save();

        $row = new WarehouseProduct();
        $row->warehouse_id = $w->id;
        $row->product_id = $product->id;
        $row->quantity = 3;
        $row->created_by = $this->creatorId;
        $row->save();

        $pos = new Pos();
        $pos->created_by = $this->creatorId;
        $pos->save();

        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $product->id;
        $line->warehouse_id = $w->id;
        $line->quantity = 8;
        $line->negative_stock_authorized_by = $authorizedBy;
        $line->save();

        PosLineStockDeductionService::deductLine($pos, $line, $this->creatorId, 'PDV00001');

        return [$product->fresh(), WarehouseProduct::where('warehouse_id', $w->id)->where('product_id', $product->id)->first(), $line->fresh()];
    }

    public function test_authorized_line_deducts_below_zero_and_logs(): void
    {
        [$product, $warehouseRow, $line] = $this->stockScenario((int) $this->manager->id);

        $this->assertEquals(-3.0, (float) $product->quantity);
        $this->assertEquals(-5.0, (float) $warehouseRow->quantity);
        $this->assertNotNull($line->stock_deducted_at);
        $this->assertEquals(1, \DB::table('pos_audit_logs')->where('action', 'negative_stock_sale')->count());
        $meta = json_decode((string) \DB::table('pos_audit_logs')->where('action', 'negative_stock_sale')->first()->meta, true);
        $this->assertEquals((int) $this->manager->id, (int) $meta['authorized_by']);
    }

    public function test_unauthorized_line_still_clamps_at_zero(): void
    {
        [$product, $warehouseRow, $line] = $this->stockScenario(null);

        $this->assertEquals(0.0, (float) $product->quantity);
        $this->assertEquals(0.0, (float) $warehouseRow->quantity);
        $this->assertNotNull($line->stock_deducted_at);
        $this->assertEquals(0, \DB::table('pos_audit_logs')->where('action', 'negative_stock_sale')->count());
    }
}
