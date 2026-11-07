<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\Branch;
use App\Models\CostCentre;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Centre master-data service (Phase 0). All hierarchy, uniqueness,
 * status and effective-date rules live here — not only in the UI — so
 * Phase 1 (allocations) can rely on the same guards.
 *
 * Every method takes the company ($creatorId) explicitly and never
 * trusts a tenant id from the caller.
 */
class CostCentreService
{
    public function create(array $data, int $creatorId, int $userId): CostCentre
    {
        $clean = $this->validate($data, $creatorId);

        return CostCentre::create(array_merge($clean, [
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'updated_by' => $userId,
        ]));
    }

    public function update(CostCentre $centre, array $data, int $creatorId, int $userId): CostCentre
    {
        $this->assertSameCompany($centre, $creatorId);

        $clean = $this->validate($data, $creatorId, $centre);

        $centre->fill(array_merge($clean, ['updated_by' => $userId]));
        $centre->save();

        return $centre->fresh();
    }

    public function deactivate(CostCentre $centre, int $creatorId, int $userId): CostCentre
    {
        $this->assertSameCompany($centre, $creatorId);

        $centre->status = CostCentre::STATUS_INACTIVE;
        $centre->updated_by = $userId;
        $centre->save();

        return $centre->fresh();
    }

    /**
     * Delete only when the centre is unreferenced. A centre with
     * children, or referenced by any other table, cannot be deleted —
     * deactivate it instead. (In Phase 0 the only possible reference is
     * kitchen_recipes.cost_centre_id; later phases add their tables to
     * referenceCount() and inherit the guard.)
     */
    public function delete(CostCentre $centre, int $creatorId): void
    {
        $this->assertSameCompany($centre, $creatorId);

        $references = $this->referenceCount($centre);
        if ($references > 0) {
            throw new CostCentreException(
                __('This cost centre cannot be deleted because it is still used (:count reference(s)). Deactivate it instead.', ['count' => $references])
            );
        }

        $centre->delete();
    }

    public function canDelete(CostCentre $centre): bool
    {
        return $this->referenceCount($centre) === 0;
    }

    /**
     * How many things reference this centre (children included)?
     * Every table is checked with a fresh Schema::hasTable() call —
     * never memoized (a memoized answer poisons later calls when the
     * schema changes in the same process, e.g. under PHPUnit).
     */
    public function referenceCount(CostCentre $centre): int
    {
        $count = CostCentre::withTrashed()->where('parent_id', $centre->id)->count();

        // Plain integer reference from the Kitchen module (its
        // cost_centre_id column predates this module). Read-only: this
        // service never writes to the Kitchen module.
        if (Schema::hasTable('kitchen_recipes')) {
            $count += (int) \DB::table('kitchen_recipes')
                ->where('cost_centre_id', $centre->id)->count();
        }

        // Future phases: their tables inherit this delete guard as soon
        // as they exist (allocation lines, asset assignments, treasury
        // dimensions, journal dimensions).
        foreach ([
            'cost_centre_allocation_lines' => 'cost_centre_id',
            'asset_cost_centre_assignments' => 'cost_centre_id',
            'treasury_line_dimensions' => 'cost_centre_id',
            'journal_line_dimensions' => 'cost_centre_id',
        ] as $table => $column) {
            if (Schema::hasTable($table)) {
                $count += (int) \DB::table($table)->where($column, $centre->id)->count();
            }
        }

        return $count;
    }

    /**
     * Phase 1 entry point: throws when the centre must not take a new
     * transaction (inactive, outside its effective window, or a
     * summary-only parent). Inactive centres stay reportable for
     * history — they just take nothing new.
     */
    public function assertCanAcceptTransactions(CostCentre $centre, ?Carbon $date = null): void
    {
        $parentsSummaryOnly = CostCentreSettings::parentsSummaryOnly((int) $centre->created_by);

        if (! $centre->canAcceptTransactions($date, $parentsSummaryOnly)) {
            if (! $centre->isActive()) {
                throw new CostCentreException(__('Cost centre :code is inactive and cannot take new transactions.', ['code' => $centre->code]));
            }

            if (! $centre->isEffectiveOn($date)) {
                throw new CostCentreException(__('Cost centre :code is outside its effective dates and cannot take new transactions.', ['code' => $centre->code]));
            }

            throw new CostCentreException(__('Cost centre :code is a summary (parent) centre and cannot take direct postings. Post to one of its children instead.', ['code' => $centre->code]));
        }
    }

    public function canAcceptTransactions(CostCentre $centre, ?Carbon $date = null): bool
    {
        try {
            $this->assertCanAcceptTransactions($centre, $date);

            return true;
        } catch (CostCentreException) {
            return false;
        }
    }

    /**
     * Full tree for the company: roots ordered by code, children nested.
     * Returns a flat list of ['centre' => CostCentre, 'depth' => int]
     * rows in tree order, ready for an indented list view.
     */
    public function treeRows(int $creatorId): array
    {
        $centres = CostCentre::forCompany($creatorId)->orderBy('code')->get();

        $byParent = [];
        foreach ($centres as $centre) {
            $byParent[$centre->parent_id ?? 0][] = $centre;
        }

        $rows = [];
        $walk = function ($parentId, $depth) use (&$walk, &$rows, $byParent) {
            foreach ($byParent[$parentId] ?? [] as $centre) {
                $rows[] = ['centre' => $centre, 'depth' => $depth];
                $walk($centre->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }

    /** All descendant ids of a centre (children, grandchildren, ...). */
    public function descendantIds(CostCentre $centre): array
    {
        $ids = [];
        $queue = [(int) $centre->id];
        $seen = [(int) $centre->id => true];

        while ($queue !== []) {
            $parentId = array_shift($queue);
            $children = CostCentre::where('parent_id', $parentId)->pluck('id')->all();
            foreach ($children as $childId) {
                $childId = (int) $childId;
                if (isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Would making $parentId the parent of $centre create a cycle?
     * A centre can never become its own ancestor.
     */
    public function wouldCreateCycle(?int $parentId, ?CostCentre $centre): bool
    {
        if ($parentId === null || $centre === null) {
            return false;
        }

        if ($parentId === (int) $centre->id) {
            return true;
        }

        return in_array($parentId, $this->descendantIds($centre), true);
    }

    /**
     * Validate master-data input. Throws CostCentreException on any
     * domain-rule violation; returns the cleaned attributes.
     */
    public function validate(array $data, int $creatorId, ?CostCentre $existing = null): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            throw new CostCentreException(__('The code field is required.'));
        }
        if (mb_strlen($code) > 50) {
            throw new CostCentreException(__('The code may not be greater than 50 characters.'));
        }

        // Unique per company — including soft-deleted rows, so a code is
        // never reused after its centre was deactivated/deleted.
        $codeTaken = CostCentre::withTrashed()
            ->where('created_by', $creatorId)
            ->where('code', $code)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($codeTaken) {
            throw new CostCentreException(__('A cost centre with code :code already exists for this company. Codes are never reused.', ['code' => $code]));
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new CostCentreException(__('The name field is required.'));
        }
        if (mb_strlen($name) > 255) {
            throw new CostCentreException(__('The name may not be greater than 255 characters.'));
        }

        $status = (string) ($data['status'] ?? CostCentre::STATUS_ACTIVE);
        if (! in_array($status, CostCentre::STATUSES, true)) {
            throw new CostCentreException(__('Invalid status.'));
        }

        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId !== null) {
            $parent = CostCentre::find($parentId);
            if (! $parent) {
                throw new CostCentreException(__('The selected parent cost centre does not exist.'));
            }
            if ((int) $parent->created_by !== $creatorId) {
                throw new CostCentreException(__('The parent cost centre belongs to another company.'));
            }
            if ($this->wouldCreateCycle($parentId, $existing)) {
                throw new CostCentreException(__('A cost centre cannot become its own ancestor. This parent would create a cycle.'));
            }
        }

        $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId !== null) {
            $branch = Branch::find($branchId);
            if (! $branch || (int) $branch->created_by !== $creatorId) {
                throw new CostCentreException(__('The selected branch belongs to another company.'));
            }
        }

        $managerId = ! empty($data['manager_user_id']) ? (int) $data['manager_user_id'] : null;
        if ($managerId !== null) {
            $manager = User::find($managerId);
            if (! $manager || ! $this->userBelongsToCompany($manager, $creatorId)) {
                throw new CostCentreException(__('The selected manager is not a user of this company.'));
            }
        }

        $effectiveFrom = ! empty($data['effective_from']) ? Carbon::parse($data['effective_from'])->startOfDay() : null;
        $effectiveTo = ! empty($data['effective_to']) ? Carbon::parse($data['effective_to'])->startOfDay() : null;
        if ($effectiveFrom && $effectiveTo && $effectiveTo->lessThan($effectiveFrom)) {
            throw new CostCentreException(__('The effective end date must be on or after the effective start date.'));
        }

        return [
            'code' => $code,
            'name' => $name,
            'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            'parent_id' => $parentId,
            'branch_id' => $branchId,
            'manager_user_id' => $managerId,
            'status' => $status,
            'effective_from' => $effectiveFrom?->toDateString(),
            'effective_to' => $effectiveTo?->toDateString(),
        ];
    }

    public function assertSameCompany(CostCentre $centre, int $creatorId): void
    {
        if ((int) $centre->created_by !== $creatorId) {
            abort(403, __('This cost centre belongs to another company.'));
        }
    }

    /** The company user itself, or a user created by the company. */
    protected function userBelongsToCompany(User $user, int $creatorId): bool
    {
        return (int) $user->id === $creatorId || (int) $user->created_by === $creatorId;
    }
}
