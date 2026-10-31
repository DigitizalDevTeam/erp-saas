<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseDocument;
use App\Models\WarehouseProduct;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WarehouseController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $query = warehouse::with(['branch', 'assignedUsers'])->where('created_by', '=', \Auth::user()->creatorId());
        // if (\Auth::user()->type !== 'company' && !empty(\Auth::user()->warehouse_id)) {
        //     $query->where('id', \Auth::user()->warehouse_id);
        // }
        $warehouses = $query->get();

        return view('warehouse.index',compact('warehouses'));

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    /**
     * Users eligible for warehouse allocation, globally for every company.
     *
     * The old code only matched `users.created_by = company id`, which
     * silently dropped every user linked to the company another way —
     * notably an existing user linked via the company_user membership
     * (UserController::store links existing users without changing their
     * created_by), and users whose default_company_id points here. That
     * is why the Allocate Users dropdown showed "No results found" for
     * companies whose staff were linked, not directly created, even
     * though the same users appear in the Users list (which uses the
     * membership-aware usersForCompany scope).
     *
     * Labels are "Name (email)" so the Choices search matches both the
     * name and the email; a blank name falls back to the email.
     *
     * $branchId filters the *loaded* list only, it is not a save rule:
     * a user qualifies for a branch when it is their pos_branch_id, when
     * it is in their branch_user grants, or when they have no branch
     * assignment at all (empty grants = all branches, per User::branches).
     * $includeIds are always kept in the list so an edit never silently
     * drops a previously allocated user who is outside the branch filter.
     *
     * @return array<int, string> user id => "Name (email)" label, ordered by name
     */
    public static function eligibleUsersForWarehouse(int $companyId, ?int $branchId = null, array $includeIds = []): array
    {
        $companyId = (int) $companyId;
        $includeIds = array_values(array_unique(array_filter(array_map('intval', $includeIds), fn ($id) => $id > 0)));

        $query = User::whereNotIn('type', ['company', 'client', 'super admin'])
            ->where(function ($q) use ($companyId, $includeIds) {
                $q->where('created_by', $companyId)
                    ->orWhere('default_company_id', $companyId);
                if (Schema::hasTable('company_user')) {
                    $memberIds = DB::table('company_user')
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->pluck('user_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    if ($memberIds !== []) {
                        $q->orWhereIn('id', $memberIds);
                    }
                }
                if ($includeIds !== []) {
                    $q->orWhereIn('id', $includeIds);
                }
            });

        if ($branchId !== null && $branchId > 0 && Schema::hasColumn('users', 'pos_branch_id')) {
            $hasBranchUser = Schema::hasTable('branch_user');
            $query->where(function ($q) use ($branchId, $hasBranchUser, $includeIds) {
                $q->where('pos_branch_id', $branchId);
                if ($hasBranchUser) {
                    $q->orWhereExists(function ($exists) use ($branchId) {
                        $exists->select(DB::raw(1))
                            ->from('branch_user')
                            ->whereColumn('branch_user.user_id', 'users.id')
                            ->where('branch_user.branch_id', $branchId);
                    });
                    // No branch assignment at all = all branches.
                    $q->orWhere(function ($all) {
                        $all->where(function ($pos) {
                            $pos->whereNull('pos_branch_id')->orWhere('pos_branch_id', 0);
                        })->whereNotExists(function ($exists) {
                            $exists->select(DB::raw(1))
                                ->from('branch_user')
                                ->whereColumn('branch_user.user_id', 'users.id');
                        });
                    });
                } else {
                    $q->orWhere(function ($pos) {
                        $pos->whereNull('pos_branch_id')->orWhere('pos_branch_id', 0);
                    });
                }
                if ($includeIds !== []) {
                    $q->orWhereIn('id', $includeIds);
                }
            });
        }

        $users = $query->orderBy('name')->orderBy('email')->get(['id', 'name', 'email']);

        $labels = [];
        foreach ($users as $user) {
            $name = trim((string) ($user->name ?? ''));
            $email = trim((string) ($user->email ?? ''));
            if ($name === '') {
                $label = $email;
            } elseif ($email !== '' && $email !== $name) {
                $label = $name.' ('.$email.')';
            } else {
                $label = $name;
            }
            $labels[(int) $user->id] = $label;
        }

        return $labels;
    }

    /**
     * JSON list for the Allocate Users dropdown, used when the branch
     * in the warehouse modal changes (and for name/email search).
     * Returns only users of the actor's company; a branch the actor's
     * company does not own returns an empty list, never another
     * company's users.
     */
    public function usersForWarehouse(Request $request)
    {
        if (! \Auth::user()->can('create warehouse') && ! \Auth::user()->can('edit warehouse')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $companyId = (int) \Auth::user()->creatorId();
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if ($branchId !== null && $branchId > 0) {
            $branch = Branch::where('id', $branchId)->where('created_by', $companyId)->first();
            if (! $branch) {
                return response()->json([]);
            }
        }

        $search = trim((string) $request->input('search', ''));
        $labels = self::eligibleUsersForWarehouse($companyId, $branchId);

        $out = [];
        foreach ($labels as $id => $label) {
            if ($search !== '' && stripos($label, $search) === false) {
                continue;
            }
            $out[] = ['id' => $id, 'label' => $label];
        }

        return response()->json($out);
    }

    public function create()
    {
        $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id');
        $users = self::eligibleUsersForWarehouse((int) \Auth::user()->creatorId());
        return view('warehouse.create', compact('branches', 'users'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(\Auth::user()->can('create warehouse'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    'name' => 'required',
                    'city_zip' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $warehouse             = new warehouse();
            $warehouse->name       = $request->name;
            $warehouse->branch_id  = $request->branch_id ?: null;
            $warehouse->address    = $request->address;
            $warehouse->city       = $request->city;
            $warehouse->city_zip   = $request->city_zip;
            $warehouse->created_by = \Auth::user()->creatorId();
            $warehouse->save();
            $this->syncAssignedUsers($warehouse, $request->input('assigned_users', []));

            return redirect()->route('warehouse.index')->with('success', __('Warehouse successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\warehouse  $warehouse
     * @return \Illuminate\Http\Response
     */
    public function show(warehouse $warehouse)
    {
        if (\Auth::user()->type !== 'company' && ($allowed = \Auth::user()->assignedWarehouseIds()) !== [] && ! in_array((int) $warehouse->id, $allowed, true)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if(\Auth::user()->can('show warehouse'))
        {
            $warehouseProducts = WarehouseProduct::where('warehouse_id', $warehouse->id)
                ->where('created_by', '=', \Auth::user()->creatorId())
                ->with(['product'])
                ->get();
            $assignedUsers = User::where('warehouse_id', $warehouse->id)
                ->where('created_by', \Auth::user()->creatorId())
                ->get();
            $pivotUsers = $warehouse->assignedUsers()->where('users.created_by', \Auth::user()->creatorId())->get();
            $assignedUsers = $pivotUsers->merge($assignedUsers)->unique('id')->values();
            $documents = WarehouseDocument::where('warehouse_id', $warehouse->id)
                ->where('created_by', \Auth::user()->creatorId())
                ->latest()
                ->get();
            return view('warehouse.show', compact('warehouse', 'warehouseProducts', 'assignedUsers', 'documents'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\warehouse  $warehouse
     * @return \Illuminate\Http\Response
     */
    public function edit(warehouse $warehouse)
    {
        $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id');
        $assignedUsers = $warehouse->assignedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $legacyAssigned = User::where('warehouse_id', $warehouse->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignedUsers = array_values(array_unique(array_merge($assignedUsers, $legacyAssigned)));
        // Branch-filtered list, but always including the already
        // allocated users, so editing never hides (and on save drops)
        // a previously allocated user outside the branch filter.
        $users = self::eligibleUsersForWarehouse(
            (int) \Auth::user()->creatorId(),
            $warehouse->branch_id ? (int) $warehouse->branch_id : null,
            $assignedUsers
        );
        if(\Auth::user()->can('edit warehouse'))
        {
            if($warehouse->created_by == \Auth::user()->creatorId())
            {
                return view('warehouse.edit', compact('warehouse', 'branches', 'users', 'assignedUsers'));
            }
            else
            {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\warehouse  $warehouse
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, warehouse $warehouse)
    {

        if(\Auth::user()->can('edit warehouse'))
        {
            if($warehouse->created_by == \Auth::user()->creatorId())
            {
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required',
                        'city_zip' => 'required',
                    ]
                );
                if($validator->fails())
                {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }

                $warehouse->name       = $request->name;
                $warehouse->branch_id  = $request->branch_id ?: null;
                $warehouse->address    = $request->address;
                $warehouse->city       = $request->city;
                $warehouse->city_zip   = $request->city_zip;
                $warehouse->save();
                $this->syncAssignedUsers($warehouse, $request->input('assigned_users', []));

                return redirect()->route('warehouse.index')->with('success', __('Warehouse successfully updated.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\warehouse  $warehouse
     * @return \Illuminate\Http\Response
     */
    public function destroy(warehouse $warehouse)
    {
        if(\Auth::user()->can('delete warehouse'))
        {
            if($warehouse->created_by == \Auth::user()->creatorId())
            {
                $warehouse->delete();


                return redirect()->route('warehouse.index')->with('success', __('Warehouse successfully deleted.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function uploadDocument(Request $request, warehouse $warehouse)
    {
        if (!\Auth::user()->can('edit warehouse') || $warehouse->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'document_file' => 'required|file|max:20480',
        ]);

        $fileName = time() . '_' . $request->file('document_file')->getClientOriginalName();
        $request->file('document_file')->storeAs('uploads/warehouse_documents', $fileName);

        WarehouseDocument::create([
            'warehouse_id' => $warehouse->id,
            'title' => $request->document_title,
            'file_path' => $fileName,
            'created_by' => \Auth::user()->creatorId(),
        ]);

        return redirect()->back()->with('success', __('Warehouse document uploaded successfully.'));
    }

    public function deleteDocument(warehouse $warehouse, $documentId)
    {
        if (!\Auth::user()->can('edit warehouse') || $warehouse->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $doc = WarehouseDocument::where('id', $documentId)
            ->where('warehouse_id', $warehouse->id)
            ->where('created_by', \Auth::user()->creatorId())
            ->first();
        if (!$doc) {
            return redirect()->back()->with('error', __('Document not found.'));
        }

        \Storage::delete('uploads/warehouse_documents/' . $doc->file_path);
        $doc->delete();
        return redirect()->back()->with('success', __('Warehouse document deleted successfully.'));
    }

    /**
     * Save users allocated to this warehouse without removing their other warehouses.
     *
     * @param  mixed  $assignedUserIds
     */
    protected function syncAssignedUsers(warehouse $warehouse, $assignedUserIds): void
    {
        $creatorId = (int) \Auth::user()->creatorId();
        $ids = is_array($assignedUserIds) ? $assignedUserIds : [$assignedUserIds];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        // A submitted user is valid when it is eligible for this
        // company (created_by, default_company_id, or active
        // company_user membership — the same scope as the dropdown),
        // or when it is already allocated to this warehouse, so an
        // edit can never silently drop a previously allocated user.
        // Branch is deliberately NOT a save rule: the branch filter
        // only decides which users are *loaded* in the dropdown.
        $alreadyAssigned = $warehouse->assignedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $legacyAssigned = User::where('warehouse_id', $warehouse->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $alreadyAssigned = array_values(array_unique(array_merge($alreadyAssigned, $legacyAssigned)));
        $eligibleIds = $ids === []
            ? []
            : array_keys(self::eligibleUsersForWarehouse($creatorId, null, $alreadyAssigned));
        $validIds = array_values(array_intersect($ids, $eligibleIds));

        $warehouse->assignedUsers()->sync($validIds);

        User::where('created_by', $creatorId)
            ->where('warehouse_id', $warehouse->id)
            ->when($validIds !== [], fn ($q) => $q->whereNotIn('id', $validIds))
            ->update(['warehouse_id' => null]);

        if ($validIds !== []) {
            User::where('created_by', $creatorId)
                ->whereIn('id', $validIds)
                ->where(function ($q) {
                    $q->whereNull('warehouse_id')->orWhere('warehouse_id', 0);
                })
                ->update(['warehouse_id' => $warehouse->id]);
        }
    }
}
