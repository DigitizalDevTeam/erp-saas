<?php

namespace Tests\Unit\HotelManagement;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelOccupancyPricingService;
use Tests\TestCase;

class HotelOccupancyPricingServiceTest extends TestCase
{
    private HotelOccupancyPricingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HotelOccupancyPricingService();

        Schema::create('hotel_price_rules', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('name');
            $table->string('rule_type', 32);
            $table->unsignedBigInteger('hotel_room_type_id')->nullable();
            $table->unsignedBigInteger('hotel_addon_id')->nullable();
            $table->unsignedInteger('adults_min')->nullable();
            $table->unsignedInteger('adults_max')->nullable();
            $table->unsignedInteger('qty_from')->default(1);
            $table->unsignedInteger('qty_to')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('price_basis', 16)->default('per_night');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('hotel_price_settings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('included_adults')->default(2);
            $table->decimal('discount_cap_manager_percent', 5, 2)->default(25);
            $table->decimal('discount_cap_frontdesk_percent', 5, 2)->default(10);
            $table->timestamps();
        });
        Schema::create('hotel_addons', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->default('other');
            $table->decimal('price', 15, 2)->default(0);
            $table->string('billing_mode', 16)->default('per_stay');
            $table->boolean('taxable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hotel_addons');
        Schema::dropIfExists('hotel_price_settings');
        Schema::dropIfExists('hotel_price_rules');

        parent::tearDown();
    }

    private function rule(array $attrs): int
    {
        return DB::table('hotel_price_rules')->insertGetId(array_merge([
            'created_by' => 1,
            'name' => 'Rule',
            'rule_type' => 'child',
            'hotel_room_type_id' => null,
            'hotel_addon_id' => null,
            'adults_min' => null,
            'adults_max' => null,
            'qty_from' => 1,
            'qty_to' => null,
            'price' => 0,
            'price_basis' => 'per_night',
            'valid_from' => null,
            'valid_to' => null,
            'priority' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function seedKidsRanges(): void
    {
        $this->rule(['name' => 'Kids small family', 'qty_from' => 1, 'qty_to' => 2, 'price' => 200]);
        $this->rule(['name' => 'Kids family pack', 'qty_from' => 3, 'qty_to' => 5, 'price' => 300]);
        $this->rule(['name' => 'Kids big group', 'qty_from' => 6, 'qty_to' => null, 'price' => 250]);
    }

    private function roomType(?int $id = null, ?int $includedAdults = null): ?HotelRoomType
    {
        if ($id === null) {
            return null;
        }
        $type = new HotelRoomType();
        $type->forceFill(['id' => $id, 'included_adults' => $includedAdults]);

        return $type;
    }

    public function test_child_rule_range_matching_flat_tier(): void
    {
        $this->seedKidsRanges();

        $result = $this->service->price(1, null, 3, 1, 5, 0);

        $this->assertCount(1, $result['occupancy_lines']);
        $line = $result['occupancy_lines'][0];
        $this->assertSame('Kids family pack', $line['rule_name']);
        $this->assertSame(300.0, $line['unit_price']);
        // Flat tier: all 5 kids at the matched range's rate, × 3 nights.
        $this->assertSame(4500.0, $line['amount']);
        $this->assertSame(4500.0, $result['occupancy_total']);
    }

    public function test_open_ended_range_matches_large_quantities(): void
    {
        $this->seedKidsRanges();

        $result = $this->service->price(1, null, 1, 2, 8, 0);

        $this->assertSame('Kids big group', $result['occupancy_lines'][0]['rule_name']);
        $this->assertSame(2000.0, $result['occupancy_total']);
    }

    public function test_no_matching_rule_is_free_and_flagged(): void
    {
        $result = $this->service->price(1, null, 2, 1, 2, 0);

        $line = $result['occupancy_lines'][0];
        $this->assertTrue($line['no_rule']);
        $this->assertNull($line['rule_id']);
        $this->assertSame(0.0, $line['amount']);
    }

    public function test_room_type_rule_beats_global_even_with_worse_priority(): void
    {
        // Scoped rule inserted first on purpose: insertion order must not decide the winner.
        $this->rule(['rule_type' => 'extra_person', 'name' => 'Deluxe extra', 'price' => 450, 'priority' => 50, 'hotel_room_type_id' => 7]);
        $this->rule(['rule_type' => 'extra_person', 'name' => 'Global extra', 'price' => 500, 'priority' => 0]);

        $scoped = $this->service->price(1, $this->roomType(7), 1, 3, 0, 1);
        $this->assertSame('Deluxe extra', $scoped['occupancy_lines'][0]['rule_name']);
        $this->assertSame(450.0, $scoped['occupancy_total']);

        $other = $this->service->price(1, $this->roomType(9), 1, 3, 0, 1);
        $this->assertSame('Global extra', $other['occupancy_lines'][0]['rule_name']);
    }

    public function test_lowest_priority_wins_within_same_scope(): void
    {
        // The correct winner is the middle row: neither first nor last inserted.
        $this->rule(['rule_type' => 'extra_person', 'name' => 'High number', 'qty_to' => 3, 'price' => 500, 'priority' => 10]);
        $this->rule(['rule_type' => 'extra_person', 'name' => 'Low number', 'qty_to' => 3, 'price' => 600, 'priority' => 1]);
        $this->rule(['rule_type' => 'extra_person', 'name' => 'Middle number', 'qty_to' => 3, 'price' => 550, 'priority' => 5]);

        $result = $this->service->price(1, null, 1, 2, 0, 2);

        $this->assertSame('Low number', $result['occupancy_lines'][0]['rule_name']);
        $this->assertSame(1200.0, $result['occupancy_total']);
    }

    public function test_child_rule_adults_band_selects_the_rule(): void
    {
        $this->rule(['name' => 'Single adult kids', 'adults_min' => 1, 'adults_max' => 1, 'price' => 300]);
        $this->rule(['name' => 'Two-plus adults kids', 'adults_min' => 2, 'adults_max' => null, 'price' => 200]);

        $one = $this->service->price(1, null, 1, 1, 2, 0);
        $this->assertSame('Single adult kids', $one['occupancy_lines'][0]['rule_name']);
        $this->assertSame(600.0, $one['occupancy_total']);

        $three = $this->service->price(1, null, 1, 3, 2, 0);
        $this->assertSame('Two-plus adults kids', $three['occupancy_lines'][0]['rule_name']);
        $this->assertSame(400.0, $three['occupancy_total']);
    }

    public function test_validity_window_excludes_out_of_season_rule(): void
    {
        $this->rule(['rule_type' => 'extra_person', 'name' => 'Summer extra', 'price' => 999, 'priority' => 0,
            'valid_from' => '2026-06-01', 'valid_to' => '2026-08-31']);
        $this->rule(['rule_type' => 'extra_person', 'name' => 'All year extra', 'price' => 400, 'priority' => 5]);

        $may = $this->service->price(1, null, 1, 2, 0, 1, [], Carbon::parse('2026-05-10'), Carbon::parse('2026-05-12'));
        $this->assertSame('All year extra', $may['occupancy_lines'][0]['rule_name']);

        $july = $this->service->price(1, null, 1, 2, 0, 1, [], Carbon::parse('2026-07-10'), Carbon::parse('2026-07-12'));
        $this->assertSame('Summer extra', $july['occupancy_lines'][0]['rule_name']);
        $this->assertSame(999.0, $july['occupancy_total']);
    }

    public function test_per_stay_basis_does_not_multiply_by_units(): void
    {
        $this->rule(['rule_type' => 'extra_person', 'name' => 'Extra flat', 'price' => 500, 'price_basis' => 'per_stay']);

        $result = $this->service->price(1, null, 4, 2, 0, 2);

        $this->assertSame(1, $result['occupancy_lines'][0]['units']);
        $this->assertSame(1000.0, $result['occupancy_total']);
    }

    public function test_per_night_prices_use_billing_units_on_monthly_stays(): void
    {
        $this->rule(['name' => 'Kids monthly', 'price' => 300]);

        // Billing units = 2 months on a monthly stay (existing convention).
        $result = $this->service->price(1, null, 2, 1, 1, 0);

        $this->assertSame(600.0, $result['occupancy_total']);
    }

    public function test_addon_rule_overrides_catalog_price_inside_its_range_only(): void
    {
        DB::table('hotel_addons')->insert([
            'id' => 5, 'name' => 'Extra Bed', 'type' => 'extra_bed', 'price' => 800,
            'billing_mode' => 'per_night', 'taxable' => 1, 'is_active' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->rule(['rule_type' => 'addon', 'hotel_addon_id' => 5, 'name' => 'Beds 3-4', 'qty_from' => 3, 'qty_to' => 4, 'price' => 650]);

        $three = $this->service->price(1, null, 2, 2, 0, 0, [['addon_id' => 5, 'qty' => 3]]);
        $line = $three['addon_lines'][0];
        $this->assertSame(650.0, $line['unit_price']);
        $this->assertNotNull($line['rule_id']);
        // Rule price replaces the unit price before the per-night billing step.
        $this->assertSame(3900.0, $line['amount']);

        $one = $this->service->price(1, null, 2, 2, 0, 0, [['addon_id' => 5, 'qty' => 1]]);
        $this->assertSame(800.0, $one['addon_lines'][0]['unit_price']);
        $this->assertNull($one['addon_lines'][0]['rule_id']);
        $this->assertSame(1600.0, $one['addons_total']);
    }

    public function test_per_stay_addon_has_no_units_multiplier(): void
    {
        DB::table('hotel_addons')->insert([
            'id' => 6, 'name' => 'Breakfast', 'type' => 'breakfast', 'price' => 300,
            'billing_mode' => 'per_stay', 'taxable' => 1, 'is_active' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->price(1, null, 5, 2, 0, 0, [['addon_id' => 6, 'qty' => 2]]);

        $this->assertSame(600.0, $result['addons_total']);
    }

    public function test_included_adults_resolution_order(): void
    {
        // No setting row → product default.
        $this->assertSame(2, $this->service->resolveIncludedAdults(1, null));

        DB::table('hotel_price_settings')->insert([
            'created_by' => 1, 'included_adults' => 3,
            'discount_cap_manager_percent' => 25, 'discount_cap_frontdesk_percent' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(3, $this->service->resolveIncludedAdults(1, null));

        // Room-type override wins over the global setting.
        $this->assertSame(4, $this->service->resolveIncludedAdults(1, $this->roomType(7, 4)));
        // Room type without an override falls back to the global setting.
        $this->assertSame(3, $this->service->resolveIncludedAdults(1, $this->roomType(7, null)));

        $this->assertSame(5, $this->service->suggestedExtraAdults(7, 2));
        $this->assertSame(0, $this->service->suggestedExtraAdults(1, 2));
    }

    public function test_zero_quantities_produce_no_lines(): void
    {
        $result = $this->service->price(1, null, 3, 2, 0, 0);

        $this->assertSame([], $result['occupancy_lines']);
        $this->assertSame(0.0, $result['occupancy_total']);
        $this->assertSame(0.0, $result['charges_total']);
    }

    public function test_adults_zero_is_tolerated(): void
    {
        // Group-created reservations can carry adults = 0.
        $this->rule(['name' => 'Kids any', 'price' => 100]);

        $result = $this->service->price(1, null, 1, 0, 1, 0);

        $this->assertSame(100.0, $result['occupancy_total']);
    }

    public function test_find_overlapping_rules(): void
    {
        $existing = $this->rule(['rule_type' => 'extra_person', 'name' => 'Extra 1-3', 'qty_from' => 1, 'qty_to' => 3, 'price' => 500]);

        $hits = $this->service->findOverlappingRules(1, ['rule_type' => 'extra_person', 'qty_from' => 2, 'qty_to' => 5]);
        $this->assertCount(1, $hits);

        $none = $this->service->findOverlappingRules(1, ['rule_type' => 'extra_person', 'qty_from' => 4, 'qty_to' => 6]);
        $this->assertCount(0, $none);

        // Different room-type scope does not compete.
        $scoped = $this->service->findOverlappingRules(1, ['rule_type' => 'extra_person', 'hotel_room_type_id' => 7, 'qty_from' => 2, 'qty_to' => 5]);
        $this->assertCount(0, $scoped);

        // Editing the same rule excludes itself.
        $self = $this->service->findOverlappingRules(1, ['rule_type' => 'extra_person', 'qty_from' => 1, 'qty_to' => 3], $existing);
        $this->assertCount(0, $self);
    }
}
