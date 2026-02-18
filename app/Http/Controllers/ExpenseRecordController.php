<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Utility;
use Illuminate\Http\Request;

class ExpenseRecordController extends Controller
{
    public function index()
    {
        if (\Auth::user()->can('manage expense')) {
            $expenses = Expense::where('created_by', \Auth::user()->creatorId())
                ->with(['expenseCategory', 'branch'])
                ->orderBy('date', 'desc')
                ->get();
            return view('expenses.index', compact('expenses'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function create()
    {
        if (\Auth::user()->can('create expense')) {
            $categories = ExpenseCategory::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id')->prepend(__('Select Branch'), 0);
            return view('expenses.create', compact('categories', 'branches'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('create expense')) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'date' => 'required|date',
                'amount' => 'required|numeric|min:0',
                'expense_category_id' => 'required|exists:expense_categories,id',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $expense = new Expense();
            $expense->name = $request->name;
            $expense->date = $request->date;
            $expense->description = $request->description;
            $expense->amount = $request->amount;
            $expense->expense_category_id = $request->expense_category_id;
            $expense->branch_id = (int) ($request->branch_id ?? 0);
            $expense->status = Expense::STATUS_PENDING;
            $expense->user_id = \Auth::user()->id;
            $expense->created_by = \Auth::user()->creatorId();

            if ($request->hasFile('attachment')) {
                $filenameWithExt = $request->file('attachment')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('attachment')->getClientOriginalExtension();
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;
                $dir = 'uploads/expenses/';
                $path = Utility::upload_file($request, 'attachment', $fileNameToStore, $dir, []);
                if ($path['flag'] == 1) {
                    $expense->attachment = $fileNameToStore;
                } else {
                    return redirect()->back()->with('error', __($path['msg']));
                }
            }

            $expense->save();
            return redirect()->route('expenses.index')->with('success', __('Expense successfully created.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function show(Expense $expense)
    {
        return redirect()->route('expenses.index');
    }

    public function edit(Expense $expense)
    {
        if (\Auth::user()->can('edit expense')) {
            if ($expense->created_by != \Auth::user()->creatorId()) {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
            $categories = ExpenseCategory::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id')->prepend(__('Select Branch'), 0);
            return view('expenses.edit', compact('expense', 'categories', 'branches'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, Expense $expense)
    {
        if (\Auth::user()->can('edit expense')) {
            if ($expense->created_by != \Auth::user()->creatorId()) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
                'date' => 'required|date',
                'amount' => 'required|numeric|min:0',
                'expense_category_id' => 'required|exists:expense_categories,id',
                'branch_id' => 'nullable|exists:branches,id',
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $expense->name = $request->name;
            $expense->date = $request->date;
            $expense->description = $request->description;
            $expense->amount = $request->amount;
            $expense->expense_category_id = $request->expense_category_id;
            $expense->branch_id = (int) ($request->branch_id ?? 0);
            // Status is only changed via approve/reject actions, not in edit form

            if ($request->hasFile('attachment')) {
                $filenameWithExt = $request->file('attachment')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('attachment')->getClientOriginalExtension();
                $fileNameToStore = $filename . '_' . time() . '.' . $extension;
                $dir = 'uploads/expenses/';
                $path = Utility::upload_file($request, 'attachment', $fileNameToStore, $dir, []);
                if ($path['flag'] == 1) {
                    $expense->attachment = $fileNameToStore;
                } else {
                    return redirect()->back()->with('error', __($path['msg']));
                }
            }

            $expense->save();
            return redirect()->route('expenses.index')->with('success', __('Expense successfully updated.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function approve(Expense $expense)
    {
        if (!\Auth::user()->can('approve expense')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->status != Expense::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only pending expenses can be approved.'));
        }
        $expense->status = Expense::STATUS_APPROVED;
        $expense->save();
        return redirect()->route('expenses.index')->with('success', __('Expense approved successfully.'));
    }

    public function reject(Expense $expense)
    {
        if (!\Auth::user()->can('reject expense')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($expense->status != Expense::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only pending expenses can be rejected.'));
        }
        $expense->status = Expense::STATUS_REJECTED;
        $expense->save();
        return redirect()->route('expenses.index')->with('success', __('Expense rejected successfully.'));
    }

    public function destroy(Expense $expense)
    {
        if (\Auth::user()->can('delete expense')) {
            if ($expense->created_by == \Auth::user()->creatorId()) {
                if (!empty($expense->attachment)) {
                    $dir = 'uploads/expenses/';
                    $filePath = $dir . $expense->attachment;
                    if (\File::exists($filePath)) {
                        \File::delete($filePath);
                    }
                }
                $expense->delete();
                return redirect()->route('expenses.index')->with('success', __('Expense successfully deleted.'));
            }
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }
}
