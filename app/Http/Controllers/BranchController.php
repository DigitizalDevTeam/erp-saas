<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    protected function warehouseOptionsForBranch(?Branch $branch = null)
    {
        $creatorId = \Auth::user()->creatorId();
        $q = warehouse::where('created_by', $creatorId)->orderBy('name');

        if ($branch) {
            $q->where(function ($qq) use ($branch) {
                $qq->where('branch_id', $branch->id)
                    ->orWhereNull('branch_id')
                    ->orWhere('id', $branch->default_warehouse_id);
            });
        }

        return $q->pluck('name', 'id')->prepend(__('Select Warehouse'), '');
    }

    public function index()
    {
        if(\Auth::user()->can('manage branch'))
        {
            $branches = Branch::with('defaultWarehouse')
                ->where('created_by', '=', \Auth::user()->creatorId())
                ->get();

            return view('branch.index', compact('branches'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create()
    {
        if(\Auth::user()->can('create branch'))
        {
            $warehouses = $this->warehouseOptionsForBranch();

            return view('branch.create', compact('warehouses'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {
        if(\Auth::user()->can('create branch'))
        {
            $creatorId = \Auth::user()->creatorId();
            $validator = \Validator::make(
                $request->all(), [
                    'name' => 'required',
                    'default_warehouse_id' => [
                        'nullable',
                        'integer',
                        Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('created_by', $creatorId)),
                    ],
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $branch             = new Branch();
            $branch->name       = $request->name;
            $branch->address    = $request->address;
            $branch->timezone   = $request->timezone ?? 'UTC';
            $branch->is_active  = $request->boolean('is_active', true);
            $branch->pos_type   = $request->pos_type ?? Branch::POS_TYPE_RETAIL;
            $branch->default_warehouse_id = $request->filled('default_warehouse_id') ? (int) $request->default_warehouse_id : null;
            $branch->created_by = $creatorId;
            $branch->save();

            if ($branch->default_warehouse_id) {
                warehouse::where('id', $branch->default_warehouse_id)
                    ->where('created_by', $creatorId)
                    ->where(function ($q) {
                        $q->whereNull('branch_id')->orWhere('branch_id', 0);
                    })
                    ->update(['branch_id' => $branch->id]);
            }

            return redirect()->route('branch.index')->with('success', __('Branch  successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show(Branch $branch)
    {
        return redirect()->route('branch.index');
    }

    public function edit(Branch $branch)
    {
        if(\Auth::user()->can('edit branch'))
        {
            if($branch->created_by == \Auth::user()->creatorId())
            {
                $warehouses = $this->warehouseOptionsForBranch($branch);

                return view('branch.edit', compact('branch', 'warehouses'));
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

    public function update(Request $request, Branch $branch)
    {
        if(\Auth::user()->can('edit branch'))
        {
            if($branch->created_by == \Auth::user()->creatorId())
            {
                $creatorId = \Auth::user()->creatorId();
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required',
                        'default_warehouse_id' => [
                            'nullable',
                            'integer',
                            Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('created_by', $creatorId)),
                        ],
                    ]
                );
                if($validator->fails())
                {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }

                $branch->name      = $request->name;
                $branch->address   = $request->address;
                $branch->timezone  = $request->timezone ?? 'UTC';
                $branch->is_active = $request->boolean('is_active', true);
                $branch->pos_type  = $request->pos_type ?? Branch::POS_TYPE_RETAIL;
                $branch->default_warehouse_id = $request->filled('default_warehouse_id') ? (int) $request->default_warehouse_id : null;
                $branch->save();

                if ($branch->default_warehouse_id) {
                    warehouse::where('id', $branch->default_warehouse_id)
                        ->where('created_by', $creatorId)
                        ->where(function ($q) use ($branch) {
                            $q->whereNull('branch_id')->orWhere('branch_id', 0)->orWhere('branch_id', $branch->id);
                        })
                        ->update(['branch_id' => $branch->id]);
                }

                return redirect()->route('branch.index')->with('success', __('Branch successfully updated.'));
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

    public function destroy(Branch $branch)
    {
        if(\Auth::user()->can('delete branch'))
        {
            if($branch->created_by == \Auth::user()->creatorId())
            {
                $employee     = Employee::where('branch_id', $branch->id)->get();
                if (count($employee) == 0) {
                    $department = Department::where('branch_id', $branch->id)->first();
                    if (!empty($department)) {
                        $designation = Designation::where('department_id', $department->id)->first();
                    }
                    if (isset($department)) {
                        Designation::where('department_id', $department->branch_id)->delete();
                        $department->delete();
                    }
                    if (isset($designation)) {
                        Designation::where('department_id', $department->branch_id)->delete();
                        $designation->delete();
                    }
                    $branch->delete();
                } else {
                    return redirect()->route('branch.index')->with('error', __('This branch has employees. Please remove the employee from this branch.'));
                }

                return redirect()->route('branch.index')->with('success', __('Branch successfully deleted.'));
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

    public function getdepartment(Request $request)
    {

        if($request->branch_id == 0)
        {
            $departments = Department::get()->pluck('name', 'id')->toArray();
        }
        else
        {
            $departments = Department::where('branch_id', $request->branch_id)->get()->pluck('name', 'id')->toArray();
        }

        return response()->json($departments);
    }

    public function getemployee(Request $request)
    {
        if(in_array('0', $request->department_id))
        {
            $employees = Employee::get()->pluck('name', 'id')->toArray();
        }
        else
        {
            $employees = Employee::whereIn('department_id', $request->department_id)->get()->pluck('name', 'id')->toArray();
        }

        return response()->json($employees);
    }
}
