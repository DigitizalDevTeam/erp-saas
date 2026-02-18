<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index()
    {
        if (\Auth::user()->can('manage expense category')) {
            $ExpenseCategories = ExpenseCategory::where('created_by', '=', \Auth::user()->creatorId())->get();
            return view('expenseCategory.index', compact('ExpenseCategories'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function create()
    {
        if (\Auth::user()->can('create expense category')) {
            return view('expenseCategory.create');
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function store(Request $request)
    {
        if (\Auth::user()->can('create expense category')) {
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $category = new ExpenseCategory();
            $category->name = $request->name;
            $category->user_id = \Auth::user()->id;
            $category->created_by = \Auth::user()->creatorId();
            $category->save();

            return redirect()->route('expense-category.index')->with('success', __('Expense category successfully created.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function show(ExpenseCategory $expense_category)
    {
        return redirect()->route('expense-category.index');
    }

    public function edit(ExpenseCategory $expense_category)
    {
        if (\Auth::user()->can('edit expense category')) {
            if ($expense_category->created_by != \Auth::user()->creatorId()) {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
            return view('expenseCategory.edit', compact('expense_category'));
        }
        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, ExpenseCategory $expense_category)
    {
        if (\Auth::user()->can('edit expense category')) {
            if ($expense_category->created_by != \Auth::user()->creatorId()) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
            $validator = \Validator::make($request->all(), [
                'name' => 'required',
            ]);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $expense_category->name = $request->name;
            $expense_category->save();

            return redirect()->route('expense-category.index')->with('success', __('Expense category successfully updated.'));
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function destroy(ExpenseCategory $expense_category)
    {
        if (\Auth::user()->can('delete expense category')) {
            if ($expense_category->created_by == \Auth::user()->creatorId()) {
                $expense_category->delete();
                return redirect()->route('expense-category.index')->with('success', __('Expense category successfully deleted.'));
            }
        }
        return redirect()->back()->with('error', __('Permission denied.'));
    }
}
