<?php

namespace Tests\Unit;

use App\Models\LowStockNotification;
use App\Models\ProductService;
use App\Models\ProductWarehouseStockAlert;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\LowStockNotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Header low-stock bell: one active alert per product + warehouse,
 * created when stock falls to/below Low, resolved when it recovers,
 * read status per user.
 */
class LowStockNotificationTest extends TestCase
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
            $table->integer('allow_negative_stock')->default(0);
            $table->integer('batch_tracking')->default(0);
            $table->integer('identifier_tracking')->default(0);
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
        Schema::create('low_stock_notifications', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_id', 'warehouse_id', 'resolved_at']);
        });
        Schema::create('low_stock_notification_reads', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'user_id']);
        });

        $user = new User();
        $user->name = 'Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);
    }

    private function makeProduct(int $low = 10): ProductService
    {
        $p = new ProductService();
        $p->name = 'Sugar';
        $p->sku = 'SUG-1';
        $p->type = 'product';
        $p->quantity = 0;
        $p->affect_stock = 1;
        $p->low_stock_threshold = $low;
        $p->created_by = 1;
        $p->save();

        return $p;
    }

    public function test_sale_below_low_creates_one_alert_and_repeat_sales_do_not_duplicate(): void
    {
        $product = $this->makeProduct(10);
        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $product->id, 'quantity' => 12, 'created_by' => 1]);

        // Stock change through the real mutation path (POS deductions use it).
        Utility::warehouse_quantity('minus', 3, $product->id, $w->id);
        $this->assertSame(1, LowStockNotification::whereNull('resolved_at')->count());
        $this->assertSame(9.0, (float) LowStockNotification::first()->quantity);

        // Still low after another sale: snapshot refreshes, no second alert.
        Utility::warehouse_quantity('minus', 2, $product->id, $w->id);
        $this->assertSame(1, LowStockNotification::whereNull('resolved_at')->count());
        $this->assertSame(7.0, (float) LowStockNotification::first()->quantity);
    }

    public function test_recovery_resolves_and_a_new_fall_creates_a_fresh_alert(): void
    {
        $product = $this->makeProduct(10);
        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $product->id, 'quantity' => 8, 'created_by' => 1]);

        LowStockNotificationService::checkProductWarehouse($product->id, $w->id, 1);
        $this->assertSame(1, LowStockNotification::whereNull('resolved_at')->count());

        WarehouseProduct::where('product_id', $product->id)->update(['quantity' => 20]);
        LowStockNotificationService::checkProductWarehouse($product->id, $w->id, 1);
        $this->assertSame(0, LowStockNotification::whereNull('resolved_at')->count());
        $this->assertSame(1, LowStockNotification::whereNotNull('resolved_at')->count());

        WarehouseProduct::where('product_id', $product->id)->update(['quantity' => 5]);
        LowStockNotificationService::checkProductWarehouse($product->id, $w->id, 1);
        $this->assertSame(1, LowStockNotification::whereNull('resolved_at')->count());
        $this->assertSame(2, LowStockNotification::count());
    }

    public function test_per_warehouse_low_wins_over_product_low(): void
    {
        $product = $this->makeProduct(20);
        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $product->id, 'quantity' => 15, 'created_by' => 1]);
        ProductWarehouseStockAlert::create(['product_id' => $product->id, 'warehouse_id' => $w->id, 'low_stock_threshold' => 5, 'max_stock_threshold' => null, 'created_by' => 1]);

        // 15 is below the product Low (20) but above the warehouse Low (5).
        LowStockNotificationService::checkProductWarehouse($product->id, $w->id, 1);
        $this->assertSame(0, LowStockNotification::count());
    }

    public function test_read_status_is_per_user(): void
    {
        $product = $this->makeProduct(10);
        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        WarehouseProduct::create(['warehouse_id' => $w->id, 'product_id' => $product->id, 'quantity' => 5, 'created_by' => 1]);
        LowStockNotificationService::checkProductWarehouse($product->id, $w->id, 1);
        $notification = LowStockNotification::first();

        $company = Auth::user();
        $staff = new User();
        $staff->name = 'Staff';
        $staff->email = 'staff@example.com';
        $staff->password = bcrypt('secret');
        $staff->type = 'employee';
        $staff->created_by = 1;
        $staff->save();

        $this->assertSame(1, LowStockNotificationService::unreadCountForUser($company));
        $this->assertSame(1, LowStockNotificationService::unreadCountForUser($staff));

        LowStockNotificationService::markRead($notification->id, $company);
        $this->assertSame(0, LowStockNotificationService::unreadCountForUser($company));
        $this->assertSame(1, LowStockNotificationService::unreadCountForUser($staff));

        LowStockNotificationService::markAllRead($staff);
        $this->assertSame(0, LowStockNotificationService::unreadCountForUser($staff));
    }

    public function test_header_contains_generic_bell_dropdown_and_endpoints(): void
    {
        $header = file_get_contents(base_path('resources/views/partials/admin/header.blade.php'));

        // The bell is generic ("Notifications"), not stock-only: low stock
        // is just the first feed plugged into it, rendered as one line
        // with its quantity information.
        $this->assertStringContainsString('ti-bell', $header);
        $this->assertStringContainsString('Notifications', $header);
        $this->assertStringContainsString('notif-badge', $header);
        $this->assertStringContainsString('notif-mark-all', $header);
        $this->assertStringContainsString('low-stock-notifications', $header);
        $this->assertStringContainsString('Low stock', $header);
        $this->assertStringNotContainsString('lsn-badge', $header);
        $this->assertStringNotContainsString('Low stock alerts', $header);
    }
}
