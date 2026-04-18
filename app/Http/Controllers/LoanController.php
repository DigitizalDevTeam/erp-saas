<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanOption;
use App\Support\PayrollChartAccountOptions;
use App\Services\EmployeeLoanScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function loanCreate($id)
    {
        $employee = Employee::find($id);
        $loan_options = LoanOption::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
        $loan = Loan::$Loantypes;
        $chartAccounts = PayrollChartAccountOptions::listLoanDeductionAccountsForCurrentUser();

        return view('loan.create', compact('employee', 'loan_options', 'loan', 'chartAccounts'));
    }

    public function store(Request $request)
    {
        if (! \Auth::user()->can('create loan')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $schedule = $request->schedule_mode === Loan::SCHEDULE_INSTALLMENT ? Loan::SCHEDULE_INSTALLMENT : Loan::SCHEDULE_LEGACY;

        if ($schedule === Loan::SCHEDULE_INSTALLMENT) {
            $validator = \Validator::make($request->all(), [
                'employee_id' => 'required',
                'loan_option' => 'required',
                'title' => 'required',
                'reason' => 'required',
                'principal_amount' => 'required|numeric|min:0.01',
                'term_months' => 'required|integer|min:1|max:600',
                'first_deduction_month' => 'required|date_format:Y-m',
                'annual_interest_rate' => 'nullable|numeric|min:0|max:100',
            ]);
        } else {
            $validator = \Validator::make($request->all(), [
                'employee_id' => 'required',
                'loan_option' => 'required',
                'title' => 'required',
                'amount' => 'required|numeric|min:0',
                'type' => 'required|in:fixed,percentage',
                'reason' => 'required',
            ]);
        }

        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $loan = new Loan();
        $loan->employee_id = $request->employee_id;
        $loan->loan_option = $request->loan_option;
        $loan->title = $request->title;
        $loan->reason = $request->reason;
        $loan->chart_account_id = $request->chart_account_id;
        $loan->created_by = \Auth::user()->creatorId();

        if ($schedule === Loan::SCHEDULE_INSTALLMENT) {
            $principal = round((float) $request->principal_amount, 2);
            $term = (int) $request->term_months;
            $rate = round((float) ($request->annual_interest_rate ?? 0), 4);
            $emi = EmployeeLoanScheduleService::calculateEmi($principal, $rate, $term);

            $loan->schedule_mode = Loan::SCHEDULE_INSTALLMENT;
            $loan->principal_amount = $principal;
            $loan->outstanding_principal = $principal;
            $loan->annual_interest_rate = $rate;
            $loan->term_months = $term;
            $loan->monthly_installment = $emi;
            $loan->amount = $emi;
            $loan->type = 'fixed';
            $loan->first_deduction_month = $request->first_deduction_month;
            $loan->installments_paid = 0;
            $loan->loan_status = Loan::STATUS_ACTIVE;

            $start = Carbon::parse($request->first_deduction_month . '-01');
            $loan->start_date = $start->format('Y-m-d');
            $loan->end_date = $start->copy()->addMonths($term)->endOfMonth()->format('Y-m-d');
        } else {
            $loan->schedule_mode = Loan::SCHEDULE_LEGACY;
            $loan->type = $request->type;
            $loan->amount = $request->amount;
            $loan->principal_amount = null;
            $loan->outstanding_principal = null;
            $loan->annual_interest_rate = 0;
            $loan->term_months = null;
            $loan->monthly_installment = null;
            $loan->first_deduction_month = null;
            $loan->installments_paid = 0;
            $loan->loan_status = Loan::STATUS_ACTIVE;
            $loan->start_date = Carbon::now()->startOfMonth()->format('Y-m-d');
            $loan->end_date = Carbon::now()->addYear()->endOfMonth()->format('Y-m-d');
        }

        $loan->save();

        return redirect()->back()->with('success', __('Loan successfully created.'));
    }

    public function show(Loan $loan)
    {
        return redirect()->route('commision.index');
    }

    public function edit($loan)
    {
        $loan = Loan::find($loan);
        if (\Auth::user()->can('edit loan')) {
            if ($loan->created_by == \Auth::user()->creatorId()) {
                $loan_options = LoanOption::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
                $loans = Loan::$Loantypes;
                $chartAccounts = PayrollChartAccountOptions::listLoanDeductionAccountsForCurrentUser();

                return view('loan.edit', compact('loan', 'loan_options', 'loans', 'chartAccounts'));
            }

            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return response()->json(['error' => __('Permission denied.')], 401);
    }

    public function update(Request $request, Loan $loan)
    {
        if (! \Auth::user()->can('edit loan')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($loan->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($loan->isInstallment() && $loan->repayments()->exists()) {
            $validator = \Validator::make($request->all(), [
                'loan_option' => 'required',
                'title' => 'required',
                'reason' => 'required',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $loan->loan_option = $request->loan_option;
            $loan->title = $request->title;
            $loan->reason = $request->reason;
            $loan->chart_account_id = $request->chart_account_id;
            $loan->save();

            return redirect()->back()->with('success', __('Loan updated. Schedule is locked after repayments begin.'));
        }

        if ($loan->isInstallment()) {
            $validator = \Validator::make($request->all(), [
                'loan_option' => 'required',
                'title' => 'required',
                'reason' => 'required',
                'principal_amount' => 'required|numeric|min:0.01',
                'term_months' => 'required|integer|min:1|max:600',
                'first_deduction_month' => 'required|date_format:Y-m',
                'annual_interest_rate' => 'nullable|numeric|min:0|max:100',
            ]);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }

            $principal = round((float) $request->principal_amount, 2);
            $term = (int) $request->term_months;
            $rate = round((float) ($request->annual_interest_rate ?? 0), 4);
            $emi = EmployeeLoanScheduleService::calculateEmi($principal, $rate, $term);

            $loan->loan_option = $request->loan_option;
            $loan->title = $request->title;
            $loan->reason = $request->reason;
            $loan->principal_amount = $principal;
            $loan->outstanding_principal = $principal;
            $loan->annual_interest_rate = $rate;
            $loan->term_months = $term;
            $loan->monthly_installment = $emi;
            $loan->amount = $emi;
            $loan->first_deduction_month = $request->first_deduction_month;
            $loan->chart_account_id = $request->chart_account_id;
            $loan->installments_paid = 0;
            $loan->loan_status = Loan::STATUS_ACTIVE;

            $start = Carbon::parse($request->first_deduction_month . '-01');
            $loan->start_date = $start->format('Y-m-d');
            $loan->end_date = $start->copy()->addMonths($term)->endOfMonth()->format('Y-m-d');
            $loan->save();

            return redirect()->back()->with('success', __('Loan successfully updated.'));
        }

        $validator = \Validator::make($request->all(), [
            'loan_option' => 'required',
            'title' => 'required',
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:fixed,percentage',
            'reason' => 'required',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $loan->loan_option = $request->loan_option;
        $loan->title = $request->title;
        $loan->type = $request->type;
        $loan->amount = $request->amount;
        $loan->reason = $request->reason;
        $loan->chart_account_id = $request->chart_account_id;
        if ($request->filled('start_date')) {
            $loan->start_date = $request->start_date;
        }
        if ($request->filled('end_date')) {
            $loan->end_date = $request->end_date;
        }
        $loan->save();

        return redirect()->back()->with('success', __('Loan successfully updated.'));
    }

    public function destroy(Loan $loan)
    {
        if (! \Auth::user()->can('delete loan')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($loan->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($loan->repayments()->exists()) {
            return redirect()->back()->with('error', __('Cannot delete a loan with recorded repayments.'));
        }

        $loan->delete();

        return redirect()->back()->with('success', __('Loan successfully deleted.'));
    }
}
