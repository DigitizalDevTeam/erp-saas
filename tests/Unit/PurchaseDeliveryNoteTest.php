<?php

namespace Tests\Unit;

use App\Http\Controllers\PurchaseController;
use App\Models\Bill;
use App\Models\BillProduct;
use App\Models\ProductExpiryLot;
use App\Models\ProductService;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\PurchaseDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Purchase delivery notes: stock comes only from a delivery note.
 * A new purchase order adds no stock at create; each delivery adds
 * exactly its delivered quantity; partial deliveries accumulate;
 * over-delivery and legacy documents are refused with nothing stocked.
 */
class PurchaseDeliveryNoteTest extends TestCase
{
    private int $creatorId = 1;

    private User $company;

    private warehouse $warehouse;

    private ProductService $product;

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
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->boolean('affect_stock')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('options_enabled')->default(false);
            $table->boolean('variants_enabled')->default(false);
            $table->boolean('batch_tracking')->default(false);
            $table->boolean('identifier_tracking')->default(false);
            $table->string('identifier_type')->nullable();
            $table->integer('warranty_days')->nullable();
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
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
        Schema::create('purchases', function ($table) {
            $table->increments('id');
            $table->string('purchase_id')->default('0');
            $table->integer('vender_id');
            $table->integer('warehouse_id');
            $table->date('purchase_date')->nullable();
            $table->integer('purchase_number')->default(0);
            $table->integer('status')->default(0);
            $table->tinyInteger('stock_on_delivery')->default(0);
            $table->integer('category_id')->default(0);
            $table->integer('purchase_quotation_id')->nullable();
            $table->integer('purchase_proforma_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->integer('quotation_id')->nullable();
            $table->integer('converted_bill_id')->nullable();
            $table->integer('converted_invoice_id')->nullable();
            $table->integer('is_invoice_converted')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_products', function ($table) {
            $table->increments('id');
            $table->integer('purchase_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();
            $table->timestamps();
        });
        Schema::create('bills', function ($table) {
            $table->increments('id');
            $table->integer('bill_id')->default(0);
            $table->integer('vender_id');
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('status')->default(0);
            $table->string('type')->nullable();
            $table->string('user_type')->nullable();
            $table->integer('category_id')->default(0);
            $table->integer('order_number')->default(0);
            $table->integer('purchase_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->boolean('suppress_inventory')->default(false);
            $table->tinyInteger('stock_on_delivery')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bill_products', function ($table) {
            $table->increments('id');
            $table->integer('bill_id');
            $table->integer('product_id');
            $table->integer('quantity')->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_delivery_notes', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('note_number')->default(0);
            $table->unsignedBigInteger('vender_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->date('delivery_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('purchase_delivery_note_items', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('purchase_delivery_note_id');
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_line_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('ordered_qty', 18, 4)->default(0);
            $table->decimal('delivered_qty', 18, 4)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('product_expiry_lots', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_product_id')->nullable();
            $table->string('source', 20)->default('purchase');
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();
            $table->string('batch_no')->nullable();
            $table->decimal('quantity_in', 16, 4)->default(0);
            $table->decimal('quantity_remaining', 16, 4)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('product_identifiers', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_product_id')->nullable();
            $table->string('identifier_type', 10)->default('serial');
            $table->string('identifier_value', 191);
            $table->string('status', 20)->default('in_stock');
            $table->date('warranty_end_date')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        // Utility::settings() caches per creator in statics; clear it so
        // each test reads this test's own settings rows.
        foreach (['getsettings', 'getsettingsid'] as $propName) {
            $prop = new \ReflectionProperty(Utility::class, $propName);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->company = new User();
        $this->company->name = 'Company';
        $this->company->email = 'company@example.com';
        $this->company->password = bcrypt('secret');
        $this->company->type = 'company';
        $this->company->save();
        $this->creatorId = (int) $this->company->id;
        $this->company->givePermissionTo(Permission::create(['name' => 'create purchase', 'guard_name' => 'web']));
        $this->company->givePermissionTo(Permission::create(['name' => 'create bill', 'guard_name' => 'web']));
        Auth::login($this->company);

        \DB::table('settings')->insert([
            ['name' => 'purchase_prefix', 'value' => 'PO', 'created_by' => $this->creatorId],
            ['name' => 'bill_prefix', 'value' => 'BILL', 'created_by' => $this->creatorId],
            ['name' => 'vendor_quote_prefix', 'value' => '#VQ', 'created_by' => $this->creatorId],
        ]);

        $this->warehouse = new warehouse();
        $this->warehouse->name = 'Main';
        $this->warehouse->created_by = $this->creatorId;
        $this->warehouse->save();

        $this->product = new ProductService();
        $this->product->name = 'Cement Bags';
        $this->product->type = 'product';
        $this->product->quantity = 0;
        $this->product->created_by = $this->creatorId;
        $this->product->save();
    }

    private function makePurchase(int $orderedQty, bool $deliveryDriven = true): array
    {
        $purchase = new Purchase();
        $purchase->purchase_id = '1';
        $purchase->vender_id = 7;
        $purchase->warehouse_id = $this->warehouse->id;
        $purchase->purchase_date = now()->toDateString();
        $purchase->purchase_number = 1;
        $purchase->status = 0;
        $purchase->stock_on_delivery = $deliveryDriven ? 1 : 0;
        $purchase->category_id = 1;
        $purchase->created_by = $this->creatorId;
        $purchase->save();

        $line = new PurchaseProduct();
        $line->purchase_id = $purchase->id;
        $line->product_id = $this->product->id;
        $line->quantity = $orderedQty;
        $line->price = 10;
        $line->save();

        return [$purchase, $line];
    }

    private function deliver(Purchase $purchase, PurchaseProduct $line, float $qty, array $extra = []): void
    {
        PurchaseDeliveryService::createNote($this->creatorId, [
            'vender_id' => $purchase->vender_id,
            'warehouse_id' => $purchase->warehouse_id,
            'purchase_id' => $purchase->id,
            'delivery_date' => now()->toDateString(),
            'items' => [array_merge([
                'source_type' => 'purchase',
                'source_line_id' => $line->id,
                'delivered_qty' => $qty,
            ], $extra)],
        ]);
    }

    private function stock(): array
    {
        $global = (float) ProductService::find($this->product->id)->quantity;
        $wh = WarehouseProduct::where('product_id', $this->product->id)->first();

        return [$global, $wh ? (float) $wh->quantity : 0.0];
    }

    public function test_purchase_store_adds_no_stock_and_marks_delivery_driven(): void
    {
        $request = Request::create('/', 'POST', [
            'vender_id' => 7,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => now()->toDateString(),
            'category_id' => 1,
            'items' => [[
                'item' => $this->product->id,
                'quantity' => 100,
                'tax' => '',
                'discount' => 0,
                'price' => 10,
                'description' => '',
            ]],
        ]);
        $request->setUserResolver(fn () => $this->company);

        (new PurchaseController())->store($request);

        $purchase = Purchase::first();
        $this->assertNotNull($purchase);
        $this->assertSame(1, (int) $purchase->stock_on_delivery);
        $this->assertSame([0.0, 0.0], $this->stock());
        $this->assertSame(0, \App\Models\StockReport::count());
    }

    public function test_delivery_adds_exact_delivered_quantity(): void
    {
        [$purchase, $line] = $this->makePurchase(100);
        $this->deliver($purchase, $line, 40);

        $this->assertSame([40.0, 40.0], $this->stock());
        $this->assertSame(40.0, PurchaseDeliveryService::deliveredQty('purchase', (int) $line->id));
        $rows = PurchaseDeliveryService::sourceLinesForPurchase($purchase->fresh());
        $this->assertSame(100.0, $rows[0]['ordered']);
        $this->assertSame(40.0, $rows[0]['delivered']);
        $this->assertSame(60.0, $rows[0]['remaining']);
    }

    public function test_partial_deliveries_accumulate_to_complete(): void
    {
        [$purchase, $line] = $this->makePurchase(100);
        $this->deliver($purchase, $line, 40);
        $this->deliver($purchase, $line, 60);

        $this->assertSame([100.0, 100.0], $this->stock());
        $rows = PurchaseDeliveryService::sourceLinesForPurchase($purchase->fresh());
        $this->assertSame(0.0, $rows[0]['remaining']);
    }

    public function test_over_delivery_leaves_stock_unchanged(): void
    {
        [$purchase, $line] = $this->makePurchase(100);
        $this->deliver($purchase, $line, 40);
        try {
            $this->deliver($purchase, $line, 61);
            $this->fail('Over-delivery should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('remaining', $e->getMessage());
        }
        $this->assertSame([40.0, 40.0], $this->stock());
        $this->assertSame(1, \App\Models\PurchaseDeliveryNote::count());
    }

    public function test_delivery_against_legacy_purchase_is_refused(): void
    {
        [$purchase, $line] = $this->makePurchase(100, false);
        try {
            $this->deliver($purchase, $line, 40);
            $this->fail('Legacy purchase delivery should have been refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already added its stock', $e->getMessage());
        }
        $this->assertSame([0.0, 0.0], $this->stock());
    }

    public function test_delivery_against_converted_bill_does_not_double_add(): void
    {
        [$purchase, $line] = $this->makePurchase(100);
        $this->deliver($purchase, $line, 40);

        $bill = new Bill();
        $bill->bill_id = 1;
        $bill->vender_id = 7;
        $bill->bill_date = now()->toDateString();
        $bill->due_date = now()->toDateString();
        $bill->status = 0;
        $bill->type = 'Bill';
        $bill->user_type = 'vendor';
        $bill->purchase_id = $purchase->id;
        $bill->suppress_inventory = true;
        $bill->created_by = $this->creatorId;
        $bill->save();
        $billLine = new BillProduct();
        $billLine->bill_id = $bill->id;
        $billLine->product_id = $this->product->id;
        $billLine->quantity = 100;
        $billLine->price = 10;
        $billLine->save();

        try {
            PurchaseDeliveryService::createNote($this->creatorId, [
                'vender_id' => 7,
                'warehouse_id' => $this->warehouse->id,
                'bill_id' => $bill->id,
                'delivery_date' => now()->toDateString(),
                'items' => [[
                    'source_type' => 'bill',
                    'source_line_id' => $billLine->id,
                    'delivered_qty' => 10,
                ]],
            ]);
            $this->fail('Delivery against a converted bill should have been refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('purchase order instead', $e->getMessage());
        }
        $this->assertSame([40.0, 40.0], $this->stock());
    }

    public function test_delivery_driven_bill_delivers_stock_direct(): void
    {
        $bill = new Bill();
        $bill->bill_id = 2;
        $bill->vender_id = 7;
        $bill->bill_date = now()->toDateString();
        $bill->due_date = now()->toDateString();
        $bill->status = 0;
        $bill->type = 'Bill';
        $bill->user_type = 'vendor';
        $bill->stock_on_delivery = 1;
        $bill->created_by = $this->creatorId;
        $bill->save();
        $billLine = new BillProduct();
        $billLine->bill_id = $bill->id;
        $billLine->product_id = $this->product->id;
        $billLine->quantity = 50;
        $billLine->price = 10;
        $billLine->save();

        PurchaseDeliveryService::createNote($this->creatorId, [
            'vender_id' => 7,
            'warehouse_id' => $this->warehouse->id,
            'bill_id' => $bill->id,
            'delivery_date' => now()->toDateString(),
            'items' => [[
                'source_type' => 'bill',
                'source_line_id' => $billLine->id,
                'delivered_qty' => 20,
            ]],
        ]);

        $this->assertSame([20.0, 20.0], $this->stock());
    }

    public function test_decimal_delivery_quantity_is_recorded_exactly(): void
    {
        [$purchase, $line] = $this->makePurchase(100);
        $this->deliver($purchase, $line, 40.5);

        $item = \App\Models\PurchaseDeliveryNoteItem::first();
        $this->assertSame(40.5, (float) $item->delivered_qty);
        // Global product quantity is a float column, so it keeps 40.5.
        // (Legacy warehouse / stock-report columns are integer — see the
        // report's limitations — so only the note is asserted exactly.)
        $this->assertSame(40.5, (float) ProductService::find($this->product->id)->quantity);
    }

    public function test_batch_delivery_creates_lot_and_requires_batch_details(): void
    {
        $this->product->batch_tracking = true;
        $this->product->save();
        [$purchase, $line] = $this->makePurchase(10);

        try {
            $this->deliver($purchase, $line, 4);
            $this->fail('Batch delivery without batch details should have been refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Batch number', $e->getMessage());
        }

        $this->deliver($purchase, $line, 4, [
            'batch_no' => 'B-1',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        $lot = ProductExpiryLot::first();
        $this->assertNotNull($lot);
        $this->assertSame(4.0, (float) $lot->quantity_in);
        $this->assertSame('B-1', $lot->batch_no);
    }

    public function test_best_price_route_and_view_exist(): void
    {
        $this->assertTrue(Route::has('vendor-quote.best-price'));
        $view = file_get_contents(resource_path('views/vendor_quote/best_price.blade.php'));
        $this->assertStringContainsString('Lowest price', $view);
    }
}
