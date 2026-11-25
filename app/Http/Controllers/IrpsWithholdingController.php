<?php

namespace App\Http\Controllers;

use App\Models\IrpsWithholdingRow;
use Illuminate\Http\Request;

class IrpsWithholdingController extends Controller
{
    public function index()
    {
        if (\Auth::user()->can('manage irps withholding')) {
            $rows = IrpsWithholdingRow::where('created_by', \Auth::user()->creatorId())
                ->orderBy('salary_from')
                ->get();

            return view('irps-withholding.index', compact('rows'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create()
    {
        if (\Auth::user()->can('manage irps withholding')) {
            return view('irps-withholding.create');
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('manage irps withholding')) {
            $validator = \Validator::make(
                $request->all(), [
                    'salary_from' => 'required|numeric|min:0',
                    'salary_to' => 'nullable|numeric|gt:salary_from',
                    'base_dep0' => 'nullable|numeric|min:0',
                    'base_dep1' => 'nullable|numeric|min:0',
                    'base_dep2' => 'nullable|numeric|min:0',
                    'base_dep3' => 'nullable|numeric|min:0',
                    'base_dep4plus' => 'nullable|numeric|min:0',
                    'coefficient' => 'nullable|numeric|min:0|max:1',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $row = new IrpsWithholdingRow();
            $row->fill($request->only([
                'salary_from', 'salary_to',
                'base_dep0', 'base_dep1', 'base_dep2', 'base_dep3', 'base_dep4plus',
                'coefficient',
            ]));
            $row->created_by = \Auth::user()->creatorId();
            $row->save();

            return redirect()->route('irps-withholding.index')->with('success', __('IRPS withholding rate successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function edit($id)
    {
        if (\Auth::user()->can('manage irps withholding')) {
            $row = IrpsWithholdingRow::where('created_by', \Auth::user()->creatorId())->findOrFail($id);

            return view('irps-withholding.edit', compact('row'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function update(Request $request, $id)
    {
        if (\Auth::user()->can('manage irps withholding')) {
            $row = IrpsWithholdingRow::where('created_by', \Auth::user()->creatorId())->findOrFail($id);

            $validator = \Validator::make(
                $request->all(), [
                    'salary_from' => 'required|numeric|min:0',
                    'salary_to' => 'nullable|numeric|gt:salary_from',
                    'base_dep0' => 'nullable|numeric|min:0',
                    'base_dep1' => 'nullable|numeric|min:0',
                    'base_dep2' => 'nullable|numeric|min:0',
                    'base_dep3' => 'nullable|numeric|min:0',
                    'base_dep4plus' => 'nullable|numeric|min:0',
                    'coefficient' => 'nullable|numeric|min:0|max:1',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $row->fill($request->only([
                'salary_from', 'salary_to',
                'base_dep0', 'base_dep1', 'base_dep2', 'base_dep3', 'base_dep4plus',
                'coefficient',
            ]));
            $row->save();

            return redirect()->route('irps-withholding.index')->with('success', __('IRPS withholding rate successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy($id)
    {
        if (\Auth::user()->can('manage irps withholding')) {
            $row = IrpsWithholdingRow::where('created_by', \Auth::user()->creatorId())->findOrFail($id);
            $row->delete();

            return redirect()->route('irps-withholding.index')->with('success', __('IRPS withholding rate successfully deleted.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
