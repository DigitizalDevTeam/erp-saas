<?php

namespace Tests\Unit;

use App\Models\PosShift;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\ProductService;
use App\Services\PosShiftReportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class ShiftReportWarehouseSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
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
        Schema::create('settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->integer('affect_stock')->default(1);
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_service_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('warehouse_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_warehouse_stock_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('status')->nullable();
            $table->integer('created_by')->nullable();
            $table->json('report_warehouse_ids')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->name = 'Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);
    }

    private function makeWarehouse(string $name, int $createdBy = 1): warehouse
    {
        $w = new warehouse();
        $w->name = $name;
        $w->created_by = $createdBy;
        $w->save();

        return $w;
    }

    private function makeLowProduct(string $name, int $warehouseId, float $qty): void
    {
        $p = new ProductService();
        $p->name = $name;
        $p->sku = strtoupper(substr($name, 0, 3)).'-1';
        $p->type = 'product';
        $p->low_stock_threshold = 10;
        $p->affect_stock = 1;
        $p->created_by = 1;
        $p->save();

        $wp = new WarehouseProduct();
        $wp->warehouse_id = $warehouseId;
        $wp->product_id = $p->id;
        $wp->quantity = $qty;
        $wp->created_by = 1;
        $wp->save();
    }

    public function test_array_filter_limits_rows_to_selected_warehouses(): void
    {
        $bar = $this->makeWarehouse('Bar');
        $kitchen = $this->makeWarehouse('Kitchen');
        $this->makeLowProduct('Adega', $bar->id, 1);
        $this->makeLowProduct('Agua', $kitchen->id, 2);
        $this->makeLowProduct('Vinho', $bar->id, 3);

        $service = new PosShiftReportService();

        $barOnly = $service->minimumStockAlertRows(1, null, [$bar->id]);
        $this->assertCount(2, $barOnly['rows']);
        $this->assertTrue($barOnly['rows']->every(fn ($r) => (int) $r->warehouse_id === (int) $bar->id));

        $both = $service->minimumStockAlertRows(1, null, [$bar->id, $kitchen->id]);
        $this->assertCount(3, $both['rows']);

        $none = $service->minimumStockAlertRows(1, null, []);
        $this->assertCount(0, $none['rows']);
    }

    public function test_null_array_keeps_legacy_single_and_all_behaviour(): void
    {
        $bar = $this->makeWarehouse('Bar');
        $kitchen = $this->makeWarehouse('Kitchen');
        $this->makeLowProduct('Adega', $bar->id, 1);
        $this->makeLowProduct('Agua', $kitchen->id, 2);

        $service = new PosShiftReportService();

        $single = $service->minimumStockAlertRows(1, $bar->id);
        $this->assertCount(1, $single['rows']);

        $all = $service->minimumStockAlertRows(1);
        $this->assertCount(2, $all['rows']);
    }

    public function test_normalize_report_warehouse_ids(): void
    {
        $bar = $this->makeWarehouse('Bar');
        $kitchen = $this->makeWarehouse('Kitchen');
        $otherCompany = $this->makeWarehouse('Other Co', 2);

        $service = new PosShiftReportService();

        $this->assertNull($service->normalizeReportWarehouseIds(null, 1));
        $this->assertSame([], $service->normalizeReportWarehouseIds([], 1));
        // Junk values and another company's warehouse are dropped.
        $this->assertSame(
            [$bar->id, $kitchen->id],
            $service->normalizeReportWarehouseIds([(string) $kitchen->id, 'x', 0, $bar->id, $otherCompany->id, 9999], 1)
        );
        $this->assertSame([], $service->normalizeReportWarehouseIds([$otherCompany->id], 1));
        $this->assertSame(
            ['Bar', 'Kitchen'],
            $service->reportWarehouseNames([$kitchen->id, $bar->id], 1)
        );
    }

    public function test_shift_stores_selection_via_cast(): void
    {
        $shift = PosShift::create([
            'status' => 'open',
            'created_by' => 1,
            'report_warehouse_ids' => [1, 2],
        ]);

        $this->assertSame([1, 2], $shift->fresh()->report_warehouse_ids);

        $plain = PosShift::create(['status' => 'open', 'created_by' => 1]);
        $this->assertNull($plain->fresh()->report_warehouse_ids);
    }

    public function test_close_paths_and_email_wire_the_selection(): void
    {
        $closeCount = file_get_contents(base_path('resources/views/pos_shift/partials/close_count.blade.php'));
        $this->assertStringContainsString('report_warehouse_ids', $closeCount);
        $this->assertStringContainsString('type="checkbox"', $closeCount);

        $shiftController = file_get_contents(app_path('Http/Controllers/PosShiftController.php'));
        $this->assertStringContainsString('normalizeReportWarehouseIds', $shiftController);
        $this->assertStringContainsString('minimumStockWarehouseNames', $shiftController);

        $posController = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $this->assertStringContainsString('normalizeReportWarehouseIds', $posController);

        $emailService = file_get_contents(app_path('Services/ShiftEndLowStockReportService.php'));
        $this->assertStringContainsString('report_warehouse_ids', $emailService);

        $migration = base_path('database/migrations/2026_10_26_130000_add_report_warehouse_ids_to_pos_shifts.php');
        $this->assertFileExists($migration);
        $this->assertStringContainsString('report_warehouse_ids', file_get_contents($migration));
    }
}
