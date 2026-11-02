<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosPayment;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Internal Consumption is not a sale: no money is received, so its
 * payment must be method 'internal' — never cash, card, cheque or
 * credit — and it must not inflate cash, card, credit or sales totals
 * (the shift-report queries used here are the same filters as
 * PosShiftReportService / PosShiftController / NightAudit).
 */
class PosInternalConsumptionPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_payments', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('amount', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        \DB::table('pos')->insert([['id' => 1, 'created_by' => 1]]);
        \DB::table('pos_payments')->insert([
            ['pos_id' => 1, 'payment_method' => 'cash', 'amount' => 1000, 'created_by' => 1],
            ['pos_id' => 1, 'payment_method' => 'internal', 'amount' => 500, 'created_by' => 1],
            ['pos_id' => 1, 'payment_method' => 'card', 'amount' => 300, 'created_by' => 1],
            ['pos_id' => 1, 'payment_method' => 'credit', 'amount' => 200, 'created_by' => 1],
            ['pos_id' => 1, 'payment_method' => null, 'amount' => 50, 'created_by' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos');

        parent::tearDown();
    }

    public function test_internal_consumption_forces_internal_method(): void
    {
        $this->assertSame(PosPayment::METHOD_INTERNAL, PosPayment::forcedMethodForDocumentType(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 'cash'));
        $this->assertSame(PosPayment::METHOD_INTERNAL, PosPayment::forcedMethodForDocumentType(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 'card'));
        $this->assertSame(PosPayment::METHOD_INTERNAL, PosPayment::forcedMethodForDocumentType(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 'cheque'));
        $this->assertSame(PosPayment::METHOD_INTERNAL, PosPayment::forcedMethodForDocumentType(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, null));
        $this->assertTrue(PosPayment::isInternalConsumptionDocument(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION));
        $this->assertFalse(PosPayment::isInternalConsumptionDocument(Pos::DOCUMENT_TYPE_CASH_SALE));
    }

    public function test_credit_sale_still_forces_credit_and_cash_sale_forces_nothing(): void
    {
        $this->assertSame(PosPayment::METHOD_CREDIT, PosPayment::forcedMethodForDocumentType(Pos::DOCUMENT_TYPE_CREDIT_SALE, 'cash'));
        $this->assertNull(PosPayment::forcedMethodForDocumentType(Pos::DOCUMENT_TYPE_CASH_SALE, 'cash'));
        $this->assertNull(PosPayment::forcedMethodForDocumentType(null, 'cash'));
    }

    public function test_internal_label_is_internal_consumption_not_cash(): void
    {
        $this->assertSame('Internal Consumption', PosPayment::labelForMethod('internal'));
        $this->assertSame('Cash', PosPayment::labelForMethod('cash'));
    }

    public function test_internal_payment_excluded_from_money_totals(): void
    {
        $posIds = [1];

        $cash = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $card = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $credit = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', PosPayment::METHOD_CREDIT)->sum('amount');
        $sales = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->whereNull('payment_method')->orWhere('payment_method', '!=', PosPayment::METHOD_INTERNAL);
        })->sum('amount');
        $internal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', PosPayment::METHOD_INTERNAL)->sum('amount');

        // Cash is 1000 + 50 legacy null-method (existing cash rule), not
        // 1550: the 500 internal amount must not leak in.
        $this->assertSame(1050.0, $cash);
        $this->assertSame(300.0, $card);
        $this->assertSame(200.0, $credit);
        // Sales = cash + card + credit + legacy null-method, not internal.
        $this->assertSame(1550.0, $sales);
        $this->assertSame(500.0, $internal);
    }

    public function test_process_payment_popup_offers_internal_option(): void
    {
        $blade = file_get_contents(base_path('resources/views/pos/show.blade.php'));

        $this->assertStringContainsString('value="internal"', $blade);
        $this->assertStringContainsString("pos.payment_internal", $blade);
        $this->assertStringContainsString("docType === 'internal_consumption'", $blade);
    }
}
