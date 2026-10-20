<?php

namespace Tests\Unit\HotelManagement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Services\HotelAddonService;
use Modules\HotelManagement\Services\HotelOccupancyPricingService;
use Modules\HotelManagement\Services\HotelPosChargeService;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Tests\TestCase;

class HotelPriceLineSyncPostTest extends TestCase
{
    private HotelAddonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HotelAddonService(
            new HotelPosChargeService(),
            new HotelReservationPricingService(),
            new HotelOccupancyPricingService()
        );

        Schema::create('hotel_reservation_price_lines', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('hotel_reservation_id')->nullable();
            $table->string('kind', 32);
            $table->string('label');
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->unsignedInteger('qty')->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->unsignedInteger('units')->default(1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedBigInteger('hotel_folio_line_id')->nullable();
            $table->timestamps();
        });
        Schema::create('hotel_folios', function ($table) {
            $table->id();
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->string('status')->default('open');
            $table->timestamps();
        });
        Schema::create('hotel_folio_lines', function ($table) {
            $table->id();
            $table->unsignedBigInteger('hotel_folio_id');
            $table->string('line_type', 32);
            $table->string('revenue_channel')->nullable();
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedBigInteger('source_pos_id')->nullable();
            $table->unsignedBigInteger('hotel_addon_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hotel_folio_lines');
        Schema::dropIfExists('hotel_folios');
        Schema::dropIfExists('hotel_reservation_price_lines');

        parent::tearDown();
    }

    private function reservation(): HotelReservation
    {
        $reservation = new HotelReservation();
        $reservation->forceFill(['id' => 5]);

        return $reservation;
    }

    private function childLine(float $amount = 4500): array
    {
        return ['kind' => 'child', 'label' => 'Kids family pack', 'rule_id' => 3,
            'qty' => 5, 'unit_price' => 300.0, 'units' => 3, 'amount' => $amount];
    }

    public function test_sync_creates_lines_and_replaces_unposted_only(): void
    {
        $reservation = $this->reservation();

        $this->service->syncReservationPriceLines($reservation, [$this->childLine()], 1);
        $this->assertSame(1, DB::table('hotel_reservation_price_lines')->count());
        $this->assertSame(4500.0, (float) DB::table('hotel_reservation_price_lines')->value('amount'));

        // Mark the child line posted, then sync a changed set: the posted
        // kind is preserved untouched, new kinds are added.
        DB::table('hotel_reservation_price_lines')->update(['hotel_folio_line_id' => 42]);
        $reservation->unsetRelation('priceLines');
        $this->service->syncReservationPriceLines($reservation, [
            $this->childLine(9999),
            ['kind' => 'extra_person', 'label' => 'Extra 5-6', 'rule_id' => 4,
                'qty' => 5, 'unit_price' => 400.0, 'units' => 3, 'amount' => 6000],
        ], 1);

        $rows = DB::table('hotel_reservation_price_lines')->orderBy('kind')->get();
        $this->assertCount(2, $rows);
        $child = $rows->firstWhere('kind', 'child');
        $this->assertSame(4500.0, (float) $child->amount);
        $this->assertSame(42, (int) $child->hotel_folio_line_id);
        $this->assertSame(6000.0, (float) $rows->firstWhere('kind', 'extra_person')->amount);
    }

    public function test_posting_writes_room_channel_lines_once_and_skips_free_lines(): void
    {
        DB::table('hotel_folios')->insert([
            'id' => 7, 'sub_total' => 0, 'tax_total' => 0, 'discount_total' => 0,
            'grand_total' => 0, 'paid_total' => 0, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $folio = HotelFolio::find(7);
        $reservation = $this->reservation();

        $this->service->syncReservationPriceLines($reservation, [
            $this->childLine(),
            ['kind' => 'extra_person', 'label' => 'Additional persons', 'rule_id' => null,
                'qty' => 2, 'unit_price' => 0.0, 'units' => 3, 'amount' => 0],
        ], 1);
        $reservation->unsetRelation('priceLines');

        $this->service->postReservationPriceLinesToFolio($reservation, $folio, 1);

        $folioLines = DB::table('hotel_folio_lines')->get();
        $this->assertCount(1, $folioLines);
        $line = $folioLines->first();
        $this->assertSame('occupancy_charge', $line->line_type);
        $this->assertSame('room', $line->revenue_channel);
        // Quantity folds the per-night units in, like addon posting.
        $this->assertSame(15, (int) $line->quantity);
        $this->assertSame(4500.0, (float) $line->line_total);
        $this->assertSame(4500.0, (float) DB::table('hotel_folios')->where('id', 7)->value('grand_total'));

        $child = DB::table('hotel_reservation_price_lines')->where('kind', 'child')->first();
        $this->assertNotNull($child->hotel_folio_line_id);
        $free = DB::table('hotel_reservation_price_lines')->where('kind', 'extra_person')->first();
        $this->assertNull($free->hotel_folio_line_id);

        // Posting again is a no-op (marker guard).
        $reservation->unsetRelation('priceLines');
        $this->service->postReservationPriceLinesToFolio($reservation, $folio->fresh(), 1);
        $this->assertSame(1, DB::table('hotel_folio_lines')->count());
    }
}
