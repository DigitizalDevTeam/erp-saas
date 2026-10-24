<?php

namespace Tests\Unit;

use App\Mail\WarehouseLowStockReportMail;
use App\Models\PosShift;
use App\Models\ProductService;
use App\Models\ProductWarehouseStockAlert;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\ShiftEndLowStockReportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shift-end low-stock email report: per warehouse, min only, sent to the
 * comma-separated receivers from Email Settings, skipped when there are
 * no receivers or nothing is low.
 */
class ShiftEndLowStockReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Utility caches settings per company in statics, and PHPUnit
        // reuses one process across tests: clear the cache so each test
        // reads its own fresh settings rows.
        $ref = new \ReflectionClass(\App\Models\Utility::class);
        $cached = $ref->getProperty('getsettingsid');
        $cached->setAccessible(true);
        $cached->setValue(null, []);
        $single = $ref->getProperty('getsettings');
        $single->setAccessible(true);
        $single->setValue(null, null);

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
        Schema::create('settings', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_service_categories', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->integer('affect_stock')->default(1);
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('warehouse_products', function ($table) {
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
        Schema::create('product_warehouse_stock_alerts', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });
        Schema::create('pos_shifts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('status')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('email_logs', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->string('source')->nullable();
            $table->string('mailable_class')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->text('meta')->nullable();
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

    private function setReceivers(string $value): void
    {
        \DB::table('settings')->insert([
            'name' => 'low_stock_warehouse_report_emails',
            'value' => $value,
            'created_by' => 1,
        ]);
    }

    private function seedLowStock(): PosShift
    {
        $w1 = new warehouse();
        $w1->name = 'BAR';
        $w1->created_by = 1;
        $w1->save();
        $w2 = new warehouse();
        $w2->name = 'COZINHA';
        $w2->created_by = 1;
        $w2->save();

        $p = new ProductService();
        $p->name = 'Sugar';
        $p->sku = 'SUG-1';
        $p->type = 'product';
        $p->quantity = 0;
        $p->affect_stock = 1;
        $p->low_stock_threshold = 10;
        $p->created_by = 1;
        $p->save();

        // Low in BAR (5 <= 10), fine in COZINHA (50 > 10).
        WarehouseProduct::create(['warehouse_id' => $w1->id, 'product_id' => $p->id, 'quantity' => 5, 'created_by' => 1]);
        WarehouseProduct::create(['warehouse_id' => $w2->id, 'product_id' => $p->id, 'quantity' => 50, 'created_by' => 1]);

        $shift = new PosShift();
        $shift->status = PosShift::STATUS_CLOSED;
        $shift->created_by = 1;
        $shift->save();

        return $shift;
    }

    public function test_sends_per_warehouse_min_only_report_to_all_receivers(): void
    {
        Mail::fake();
        $this->setReceivers('boss@example.com, staff@example.com');
        $shift = $this->seedLowStock();

        $sent = ShiftEndLowStockReportService::sendForShift($shift);

        $this->assertSame(1, $sent);
        Mail::assertSent(WarehouseLowStockReportMail::class, function ($mail) {
            $rows = $mail->data['min'] ?? [];
            if (count($rows) !== 1) {
                return false;
            }

            return $rows[0]['name'] === 'Sugar'
                && $rows[0]['warehouse'] === 'BAR'
                && (float) $rows[0]['qty'] === 5.0
                && (int) $rows[0]['min'] === 10;
        });
    }

    public function test_skips_when_no_receivers_configured(): void
    {
        Mail::fake();
        $shift = $this->seedLowStock();

        $this->assertSame(0, ShiftEndLowStockReportService::sendForShift($shift));
        Mail::assertNothingSent();
    }

    public function test_skips_when_nothing_is_low(): void
    {
        Mail::fake();
        $this->setReceivers('boss@example.com');
        $shift = $this->seedLowStock();
        WarehouseProduct::query()->update(['quantity' => 50]);

        $this->assertSame(0, ShiftEndLowStockReportService::sendForShift($shift));
        Mail::assertNothingSent();
    }

    public function test_email_settings_form_has_the_new_receiver_field(): void
    {
        $view = file_get_contents(base_path('resources/views/settings/company.blade.php'));

        $this->assertStringContainsString('low_stock_warehouse_report_emails', $view);
        $this->assertStringContainsString('Product Low Stock By Warehouses report', $view);
    }
}
