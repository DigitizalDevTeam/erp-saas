<?php

namespace Tests\Unit;

use App\Http\Controllers\WarehouseController;
use App\Models\User;
use App\Models\warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Warehouse Allocate Users dropdown.
 *
 * Root cause: WarehouseController listed users only by
 * `created_by = company id`, so users linked to a company through
 * the company_user membership (existing users linked by
 * UserController::store, whose created_by is never changed) or
 * through default_company_id never appeared, and the dropdown
 * showed "No results found". The fix uses the same membership-aware
 * scope as the Users list, labels users "Name (email)" so search
 * matches name or email, and keeps already-allocated users in the
 * edit list even when they fall outside a branch filter.
 */
class WarehouseAllocateUsersTest extends TestCase
{
    private int $companyId = 1;

    private User $company;

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
            $table->integer('pos_branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->timestamps();
        });
        Schema::create('company_user', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('company_id');
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_user', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id');
            $table->integer('user_id');
            $table->timestamps();
            $table->unique(['warehouse_id', 'user_id']);
        });

        $this->company = User::create([
            'name' => 'UTHARA, Lda',
            'email' => 'company@example.com',
            'type' => 'company',
            'created_by' => 0,
            'default_company_id' => null,
        ]);
        $this->companyId = (int) $this->company->id;
        DB::table('company_user')->insert([
            'user_id' => $this->companyId,
            'company_id' => $this->companyId,
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    private function makeUser(string $name, string $email, string $type, ?int $createdBy, ?int $defaultCompany = null, ?int $posBranch = null): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'type' => $type,
            'created_by' => $createdBy,
            'default_company_id' => $defaultCompany,
            'pos_branch_id' => $posBranch,
        ]);
    }

    private function linkToCompany(User $user, string $status = 'active'): void
    {
        DB::table('company_user')->insert([
            'user_id' => (int) $user->id,
            'company_id' => $this->companyId,
            'status' => $status,
            'is_default' => false,
        ]);
    }

    public function test_membership_only_user_appears_in_dropdown(): void
    {
        // The bug: created_by points elsewhere, the user is only
        // linked to this company through company_user.
        $linked = $this->makeUser('Daniel Linked', 'daniel@example.com', 'employee', 999);
        $this->linkToCompany($linked);

        $labels = WarehouseController::eligibleUsersForWarehouse($this->companyId);

        $this->assertArrayHasKey((int) $linked->id, $labels);
        $this->assertSame('Daniel Linked (daniel@example.com)', $labels[(int) $linked->id]);
    }

    public function test_default_company_user_and_created_by_user_appear(): void
    {
        $byDefault = $this->makeUser('Maria Default', 'maria@example.com', 'employee', 999, $this->companyId);
        $byCreated = $this->makeUser('Ana Created', 'ana@example.com', 'employee', $this->companyId);

        $labels = WarehouseController::eligibleUsersForWarehouse($this->companyId);

        $this->assertArrayHasKey((int) $byDefault->id, $labels);
        $this->assertArrayHasKey((int) $byCreated->id, $labels);
    }

    public function test_client_company_and_super_admin_are_excluded(): void
    {
        $client = $this->makeUser('Client One', 'client@example.com', 'client', $this->companyId);
        $companyUser = $this->makeUser('Other Company', 'other@example.com', 'company', $this->companyId);
        $super = $this->makeUser('Super', 'super@example.com', 'super admin', $this->companyId);
        $pending = $this->makeUser('Pending Link', 'pending@example.com', 'employee', 999);
        $this->linkToCompany($pending, 'pending');

        $labels = WarehouseController::eligibleUsersForWarehouse($this->companyId);

        $this->assertArrayNotHasKey((int) $client->id, $labels);
        $this->assertArrayNotHasKey((int) $companyUser->id, $labels);
        $this->assertArrayNotHasKey((int) $super->id, $labels);
        $this->assertArrayNotHasKey((int) $pending->id, $labels);
    }

    public function test_label_allows_search_by_name_or_email(): void
    {
        $user = $this->makeUser('Daniel Linked', 'daniel@example.com', 'employee', $this->companyId);
        $labels = WarehouseController::eligibleUsersForWarehouse($this->companyId);
        $label = $labels[(int) $user->id];

        $this->assertNotFalse(stripos($label, 'Daniel'));
        $this->assertNotFalse(stripos($label, 'daniel@example.com'));
        $this->assertFalse(stripos($label, 'nobody@example.com') !== false);
    }

    public function test_branch_filter_keeps_unassigned_and_granted_users_only(): void
    {
        $byPos = $this->makeUser('Pos Branch', 'pos@example.com', 'employee', $this->companyId, null, 5);
        $byGrant = $this->makeUser('Grant Branch', 'grant@example.com', 'employee', $this->companyId);
        DB::table('branch_user')->insert(['user_id' => (int) $byGrant->id, 'branch_id' => 6]);
        $unassigned = $this->makeUser('No Branch', 'nobranch@example.com', 'employee', $this->companyId);
        $otherBranch = $this->makeUser('Other Branch', 'otherbranch@example.com', 'employee', $this->companyId, null, 6);

        $forFive = WarehouseController::eligibleUsersForWarehouse($this->companyId, 5);
        $forSix = WarehouseController::eligibleUsersForWarehouse($this->companyId, 6);

        $this->assertArrayHasKey((int) $byPos->id, $forFive);
        $this->assertArrayHasKey((int) $unassigned->id, $forFive);
        $this->assertArrayNotHasKey((int) $byGrant->id, $forFive);
        $this->assertArrayNotHasKey((int) $otherBranch->id, $forFive);

        $this->assertArrayHasKey((int) $byGrant->id, $forSix);
        $this->assertArrayHasKey((int) $otherBranch->id, $forSix);
        $this->assertArrayHasKey((int) $unassigned->id, $forSix);
        $this->assertArrayNotHasKey((int) $byPos->id, $forSix);
    }

    public function test_assigned_user_survives_branch_filter_in_edit_list(): void
    {
        $assigned = $this->makeUser('Assigned Other', 'assigned@example.com', 'employee', $this->companyId, null, 6);

        $labels = WarehouseController::eligibleUsersForWarehouse($this->companyId, 5, [(int) $assigned->id]);

        $this->assertArrayHasKey((int) $assigned->id, $labels);
    }

    public function test_sync_saves_membership_user_and_drops_ineligible_user(): void
    {
        Auth::login($this->company);

        $linked = $this->makeUser('Daniel Linked', 'daniel@example.com', 'employee', 999);
        $this->linkToCompany($linked);
        $legacy = $this->makeUser('Ana Created', 'ana@example.com', 'employee', $this->companyId);
        $ineligible = $this->makeUser('Stranger', 'stranger@example.com', 'employee', 999);

        $warehouse = warehouse::create([
            'name' => 'GERAL',
            'branch_id' => null,
            'created_by' => $this->companyId,
        ]);

        $controller = new WarehouseController();
        $method = new \ReflectionMethod($controller, 'syncAssignedUsers');
        $method->setAccessible(true);
        $method->invoke($controller, $warehouse, [(int) $linked->id, (int) $legacy->id, (int) $ineligible->id]);

        $saved = $warehouse->fresh()->assignedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        sort($saved);
        $expected = [(int) $linked->id, (int) $legacy->id];
        sort($expected);
        $this->assertSame($expected, $saved);

        // Clearing the list removes the allocation, and it stays
        // removed after a refresh (fresh warehouse read).
        $method->invoke($controller, $warehouse, []);
        $this->assertSame([], $warehouse->fresh()->assignedUsers()->pluck('users.id')->all());
    }

    public function test_sync_keeps_previously_allocated_user(): void
    {
        Auth::login($this->company);

        $assigned = $this->makeUser('Assigned Other', 'assigned@example.com', 'employee', 999);
        $warehouse = warehouse::create([
            'name' => 'GERAL',
            'branch_id' => null,
            'created_by' => $this->companyId,
        ]);
        DB::table('warehouse_user')->insert([
            'warehouse_id' => (int) $warehouse->id,
            'user_id' => (int) $assigned->id,
        ]);

        $controller = new WarehouseController();
        $method = new \ReflectionMethod($controller, 'syncAssignedUsers');
        $method->setAccessible(true);
        $method->invoke($controller, $warehouse, [(int) $assigned->id]);

        $saved = $warehouse->fresh()->assignedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $assigned->id], $saved);
    }
}
