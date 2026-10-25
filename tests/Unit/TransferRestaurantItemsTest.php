<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Services\RestaurantItemTransferService;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Transfer items between open tables: ticked lines move to the destination
 * check, unticked lines stay, and the source table is only freed when no
 * saved line and no unsent session draft is left on it.
 */
class TransferRestaurantItemsTest extends TestCase
{
    private RestaurantItemTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->string('pos_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('removed_from_cart_at')->nullable();
            $table->timestamps();
        });

        $this->service = new RestaurantItemTransferService();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos');

        parent::tearDown();
    }

    private function check(): Pos
    {
        $pos = new Pos();
        $pos->pos_id = 'PDV' . random_int(10000, 99999);
        $pos->created_by = 1;
        $pos->save();

        return $pos;
    }

    private function line(Pos $pos, array $overrides = []): PosProduct
    {
        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $overrides['product_id'] ?? 10;
        $line->quantity = $overrides['quantity'] ?? 1;
        $line->status = $overrides['status'] ?? PosProduct::STATUS_PENDING;
        $line->sent_at = $overrides['sent_at'] ?? now();
        if (array_key_exists('voided_at', $overrides)) {
            $line->voided_at = $overrides['voided_at'];
        }
        if (array_key_exists('removed_from_cart_at', $overrides)) {
            $line->removed_from_cart_at = $overrides['removed_from_cart_at'];
        }
        $line->save();

        return $line;
    }

    public function test_partial_transfer_moves_only_ticked_lines_and_keeps_source_open(): void
    {
        $source = $this->check();
        $target = $this->check();
        $a = $this->line($source);
        $b = $this->line($source);
        $stay = $this->line($source);

        $result = $this->service->transferLines($source, $target, [$a->id, $b->id]);

        $this->assertSame(2, $result['moved_count']);
        $this->assertFalse($result['source_emptied']);
        $this->assertSame($target->id, (int) $a->fresh()->pos_id);
        $this->assertSame($target->id, (int) $b->fresh()->pos_id);
        $this->assertSame($source->id, (int) $stay->fresh()->pos_id);
    }

    public function test_full_transfer_empties_source_when_no_session_draft(): void
    {
        $source = $this->check();
        $target = $this->check();
        $a = $this->line($source);
        $b = $this->line($source);

        $result = $this->service->transferLines($source, $target, [$a->id, $b->id]);

        $this->assertTrue($result['source_emptied']);
    }

    public function test_unsent_session_draft_keeps_source_occupied(): void
    {
        $source = $this->check();
        $target = $this->check();
        $a = $this->line($source);

        $result = $this->service->transferLines($source, $target, [$a->id], [['product_id' => 99]]);

        $this->assertFalse($result['source_emptied']);
    }

    public function test_voided_line_is_rejected_and_nothing_moves(): void
    {
        $source = $this->check();
        $target = $this->check();
        $good = $this->line($source);
        $voided = $this->line($source, ['voided_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->service->transferLines($source, $target, [$good->id, $voided->id]);
        } finally {
            $this->assertSame($source->id, (int) $good->fresh()->pos_id);
        }
    }

    public function test_cancelled_status_line_is_rejected(): void
    {
        $source = $this->check();
        $target = $this->check();
        $line = $this->line($source, ['status' => PosProduct::STATUS_CANCELLED]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->transferLines($source, $target, [$line->id]);
    }

    public function test_line_from_another_check_is_rejected(): void
    {
        $source = $this->check();
        $other = $this->check();
        $target = $this->check();
        $foreign = $this->line($other);

        $this->expectException(InvalidArgumentException::class);
        $this->service->transferLines($source, $target, [$foreign->id]);
    }

    public function test_empty_selection_is_rejected(): void
    {
        $source = $this->check();
        $target = $this->check();

        $this->expectException(InvalidArgumentException::class);
        $this->service->transferLines($source, $target, []);
    }
}
