<?php

namespace Tests\Unit;

use App\Exceptions\CostCentreException;
use App\Models\CostCentre;
use App\Services\CostCentreService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CostCentreServiceTest extends TestCase
{
    protected CostCentreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('manager_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code'], 'cc_company_code_uq');
        });

        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('kitchen_recipes', function ($table) {
            $table->increments('id');
            $table->integer('cost_centre_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremployee@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
            ['id' => 2, 'name' => 'Beira', 'created_by' => 4],
        ]);

        $this->service = new CostCentreService();
    }

    protected function tearDown(): void
    {
        foreach (['cost_centres', 'branches', 'settings', 'kitchen_recipes', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function payload(string $code, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => 'Centre '.$code,
            'status' => 'active',
        ], $overrides);
    }

    public function test_self_parent_is_rejected(): void
    {
        $centre = $this->service->create($this->payload('ADM'), 1, 1);

        $this->expectException(CostCentreException::class);
        $this->service->update($centre, $this->payload('ADM', ['parent_id' => $centre->id]), 1, 1);
    }

    public function test_grandchild_as_parent_is_rejected(): void
    {
        $root = $this->service->create($this->payload('ROOT'), 1, 1);
        $child = $this->service->create($this->payload('CHILD', ['parent_id' => $root->id]), 1, 1);
        $grandchild = $this->service->create($this->payload('GRAND', ['parent_id' => $child->id]), 1, 1);

        // Root cannot take its own grandchild as parent (cycle).
        $this->expectException(CostCentreException::class);
        $this->service->update($root, $this->payload('ROOT', ['parent_id' => $grandchild->id]), 1, 1);

        // Child cannot take its own child as parent either.
        $this->expectException(CostCentreException::class);
        $this->service->update($child, $this->payload('CHILD', ['parent_id' => $grandchild->id]), 1, 1);
    }

    public function test_valid_reparenting_is_allowed(): void
    {
        $rootA = $this->service->create($this->payload('A'), 1, 1);
        $rootB = $this->service->create($this->payload('B'), 1, 1);
        $child = $this->service->create($this->payload('C', ['parent_id' => $rootA->id]), 1, 1);

        $moved = $this->service->update($child, $this->payload('C', ['parent_id' => $rootB->id]), 1, 1);

        $this->assertSame($rootB->id, (int) $moved->parent_id);
        $this->assertSame([], $this->service->descendantIds($rootA));
        $this->assertSame([$child->id], $this->service->descendantIds($rootB));
    }

    public function test_code_unique_per_company_but_reusable_across_companies(): void
    {
        $this->service->create($this->payload('LOG'), 1, 1);

        // Same code in another company is fine.
        $other = $this->service->create($this->payload('LOG'), 4, 4);
        $this->assertSame('LOG', $other->code);

        // Same code in the same company is rejected.
        $this->expectException(CostCentreException::class);
        $this->service->create($this->payload('LOG'), 1, 1);
    }

    public function test_code_never_reused_after_delete(): void
    {
        $centre = $this->service->create($this->payload('OLD'), 1, 1);
        $this->service->delete($centre, 1);

        $this->expectException(CostCentreException::class);
        $this->service->create($this->payload('OLD'), 1, 1);
    }

    public function test_cross_company_parent_is_rejected(): void
    {
        $foreign = $this->service->create($this->payload('FOR'), 4, 4);

        $this->expectException(CostCentreException::class);
        $this->service->create($this->payload('LOC', ['parent_id' => $foreign->id]), 1, 1);
    }

    public function test_cross_company_manager_and_branch_are_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->service->create($this->payload('M1', ['manager_user_id' => 5]), 1, 1);

        $this->expectException(CostCentreException::class);
        $this->service->create($this->payload('M2', ['branch_id' => 2]), 1, 1);
    }

    public function test_same_company_manager_and_branch_are_accepted(): void
    {
        $centre = $this->service->create($this->payload('OK', ['manager_user_id' => 2, 'branch_id' => 1]), 1, 1);

        $this->assertSame(2, (int) $centre->manager_user_id);
        $this->assertSame(1, (int) $centre->branch_id);
    }

    public function test_effective_to_before_effective_from_is_rejected(): void
    {
        $this->expectException(CostCentreException::class);
        $this->service->create($this->payload('DATES', [
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-01-01',
        ]), 1, 1);
    }

    public function test_deactivated_centre_cannot_take_new_transactions(): void
    {
        $centre = $this->service->create($this->payload('ACT'), 1, 1);
        $this->assertTrue($this->service->canAcceptTransactions($centre));

        $this->service->deactivate($centre, 1, 1);
        $fresh = $centre->fresh();

        $this->assertSame('inactive', $fresh->status);
        $this->assertFalse($this->service->canAcceptTransactions($fresh));

        $this->expectException(CostCentreException::class);
        $this->service->assertCanAcceptTransactions($fresh);
    }

    public function test_effective_date_window_blocks_new_transactions(): void
    {
        $centre = $this->service->create($this->payload('WIN', [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ]), 1, 1);

        $this->assertTrue($this->service->canAcceptTransactions($centre, Carbon::parse('2026-06-15')));
        $this->assertFalse($this->service->canAcceptTransactions($centre, Carbon::parse('2025-12-31')));
        $this->assertFalse($this->service->canAcceptTransactions($centre, Carbon::parse('2027-01-01')));
    }

    public function test_parent_with_children_is_summary_only_and_cannot_take_postings(): void
    {
        $parent = $this->service->create($this->payload('PAR'), 1, 1);
        $child = $this->service->create($this->payload('LEAF', ['parent_id' => $parent->id]), 1, 1);

        // D2: parents are summary-only.
        $this->assertFalse($this->service->canAcceptTransactions($parent->fresh()));
        $this->assertTrue($this->service->canAcceptTransactions($child->fresh()));

        $this->expectException(CostCentreException::class);
        $this->service->assertCanAcceptTransactions($parent->fresh());
    }

    public function test_delete_blocked_with_children_but_allowed_when_unreferenced(): void
    {
        $parent = $this->service->create($this->payload('PAR'), 1, 1);
        $child = $this->service->create($this->payload('LEAF', ['parent_id' => $parent->id]), 1, 1);

        $this->expectException(CostCentreException::class);
        $this->service->delete($parent, 1);

        // The leaf itself is unreferenced and childless: deletable.
        $this->service->delete($child, 1);
        $this->assertSoftDeleted('cost_centres', ['id' => $child->id]);

        // Now the parent has no live children — but its soft-deleted child
        // still counts as a reference, so it stays protected.
        $this->assertFalse($this->service->canDelete($parent->fresh()));
    }

    public function test_delete_blocked_when_referenced_by_kitchen_recipe(): void
    {
        $centre = $this->service->create($this->payload('REF'), 1, 1);
        DB::table('kitchen_recipes')->insert(['cost_centre_id' => $centre->id, 'created_by' => 1]);

        $this->assertSame(1, $this->service->referenceCount($centre));

        $this->expectException(CostCentreException::class);
        $this->service->delete($centre, 1);
    }

    public function test_tree_rows_render_parent_before_children(): void
    {
        $root = $this->service->create($this->payload('B-ROOT'), 1, 1);
        $child = $this->service->create($this->payload('A-CHILD', ['parent_id' => $root->id]), 1, 1);
        $other = $this->service->create($this->payload('A-ROOT'), 1, 1);

        $rows = $this->service->treeRows(1);

        $this->assertSame([$other->id, $root->id, $child->id], array_map(fn ($r) => $r['centre']->id, $rows));
        $this->assertSame([0, 0, 1], array_map(fn ($r) => $r['depth'], $rows));
    }
}
