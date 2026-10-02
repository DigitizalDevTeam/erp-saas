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
    public function create()
    {
        $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id');
        $users = User::where('created_by', \Auth::user()->creatorId())
            ->where('type', '!=', 'company')
            ->pluck('name', 'id');
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
        $users = User::where('created_by', \Auth::user()->creatorId())
            ->where('type', '!=', 'company')
            ->pluck('name', 'id');
        $assignedUsers = $warehouse->assignedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $legacyAssigned = User::where('warehouse_id', $warehouse->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignedUsers = array_values(array_unique(array_merge($assignedUsers, $legacyAssigned)));
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

        $validIds = $ids === []
            ? []
            : User::where('created_by', $creatorId)
                ->where('type', '!=', 'company')
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

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
