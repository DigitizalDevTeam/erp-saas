<?php

namespace App\Http\Controllers;

use App\Exports\AccountStatementExport;
use App\Exports\BalanceSheetExport;
use App\Exports\LeaveReportExport;
use App\Exports\PayrollExport;
use App\Exports\ProductStockExport;
use App\Exports\ProfitLossExport;
use App\Exports\PosSalesByCategoryExport;
use App\Exports\ReceivableExport;
use App\Exports\SalesReportExport;
use App\Exports\TrialBalancExport;
use App\Models\AttendanceEmployee;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillProduct;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountSubType;
use App\Models\ChartOfAccountType;
use App\Models\ClientDeal;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DebitNote;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\Lead;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Payment;
use App\Models\PaySlip;
use App\Models\Pipeline;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\PosShift;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Purchase;
use App\Models\Revenue;
use App\Models\Source;
use App\Models\StockReport;
use App\Models\Tax;
use App\Models\TransactionLines;
use App\Models\User;
use App\Models\UserDeal;
use App\Models\Utility;
use App\Services\PosShiftReportService;
use App\Models\Vender;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{

    public function incomeSummary(Request $request)
    {
        if (\Auth::user()->can('income report')) {
            $account = BankAccount::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('holder_name', 'id');
            $account->prepend('All', '');
            $customer = Customer::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $customer->prepend('Select Customer', '');
            $category = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->where('type', '=', 'income')->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');

            if ($request->period === 'quarterly') {
                $month = [
                    'January-March',
                    'April-June',
                    'July-September',
                    'Octomber-December',
                ];
            } elseif ($request->period === 'half-yearly') {
                $month = [
                    'January-June',
                    'July-December',
                ];
            } elseif ($request->period === 'yearly') {
                $month = array_values(array_reverse($this->yearList()));
            } else {
                $month = $this->yearMonth();
            }
            $periods = Budget::$period;

            $data['monthList'] = $month;
            $data['yearList'] = $this->yearList();
            $data['periods'] = $periods;
            $filter['category'] = __('All');
            $filter['customer'] = __('All');

            if ($request->period === 'yearly') {
            $year = array_reverse($this->yearList());
            $yearList = [];
            foreach ($year as $value) {
                $yearList[$value] = $value;
            }
            }
            else
            {
            $yearList[($request->year) ? $request->year : date('Y')] = ($request->year) ? $request->year : date('Y');
            }

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            if (isset($request->period)) {
                $period = $request->period;
            } else {
                $period = 'monthly';
            }
            $data['currentYear'] = $year;

            // ------------------------------REVENUE INCOME-----------------------------------

                $incomes = Revenue::selectRaw('sum(revenues.amount) as amount,MONTH(date) as month,YEAR(date) as year, product_service_categories.name as category_id')->leftjoin('product_service_categories', 'revenues.category_id', '=', 'product_service_categories.id')->where('product_service_categories.type', '=', 'income');
                $incomes->where('revenues.created_by', '=', \Auth::user()->creatorId());
            if ($request->period != 'yearly') {
                $incomes->whereRAW('YEAR(date) =?', [$year]);
            }

            if (!empty($request->category)) {
                $incomes->where('category_id', '=', $request->category);
                $cat = ProductServiceCategory::find($request->category);
                $filter['category'] = !empty($cat) ? $cat->name : '';
            }

            if (!empty($request->customer)) {
                $incomes->where('customer_id', '=', $request->customer);
                $cust = Customer::find($request->customer);
                $filter['customer'] = !empty($cust) ? $cust->name : '';
            }

            $incomes->groupBy('month', 'year', 'category_id');
            $incomes = $incomes->get();

            $tmpArray = [];
            foreach ($incomes as $income) {
                $tmpArray[$income->category_id][$income->year][$income->month] = $income->amount;
            }
            $array = [];

            foreach ($tmpArray as $key => $yearData) {
                $array[$key] = [];

                foreach ($yearList as $targetYear) {
                    $array[$key][$targetYear] = [];

                    for ($i = 1; $i <= 12; $i++) {
                        $array[$key][$targetYear][$i] = 0;
                    }

                    if (isset($yearData[$targetYear])) {
                        foreach ($yearData[$targetYear] as $month => $value) {
                            $array[$key][$targetYear][$month] = (float) $value; // Convert the value to float if needed
                        }
                    }
                }
            }

            //---------------------------INVOICE INCOME-----------------------------------------------

            $invoices = Invoice::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,product_service_categories.name as category_id,invoice_id,invoices.id')
                ->leftjoin('product_service_categories', 'invoices.category_id', '=', 'product_service_categories.id')
                ->where('invoices.created_by', \Auth::user()->creatorId())->where('status', '!=', 0);

                if ($request->period != 'yearly') {
            $invoices->whereRAW('YEAR(send_date) =?', [$year]);
                }

            if (!empty($request->customer)) {
                $invoices->where('customer_id', '=', $request->customer);
            }

            if (!empty($request->category)) {
                $invoices->where('category_id', '=', $request->category);
            }

            $invoices = $invoices->get();

            // ------------------------------------------ invoice ------------------------------------------

            $invoiceTmpArray = [];

            foreach ($invoices as $invoice) {
                $invoiceTmpArray[$invoice->category_id][$invoice->year][$invoice->month][] = $invoice->getTotal();
            }

            $invoiceArray = [];

            foreach ($invoiceTmpArray as $key => $yearData) {
                $invoiceArray[$key] = [];

                foreach ($yearList as $targetYear) {
                    $invoiceArray[$key][$targetYear] = [];

                    for ($i = 1; $i <= 12; $i++) {
                        $invoiceArray[$key][$targetYear][$i] = 0;
                    }

                    if (isset($yearData[$targetYear])) {
                        foreach ($yearData[$targetYear] as $month => $values) {
                            if (is_array($values)) {
                                $sum = array_sum($values);
                                $invoiceArray[$key][$targetYear][$month] = $sum;
                            } else {
                                $invoiceArray[$key][$targetYear][$month] = (float) $values;
                            }
                        }
                    }
                }
            }

            $invoicesum = Utility::billInvoiceData($invoiceArray, $request , $yearList);

            $invoiceTotalArray = [];

            foreach ($invoices as $invoice) {
                $invoiceTotalArray[$invoice->year][$invoice->month][] = $invoice->getTotal();
            }
            // ------------------------------------------ income ------------------------------------------

            $incomeArr = [];
            $invoiceArr = [];
            $incomesum = [];

        foreach ($yearList as $year) {
            $invoiceArr[$year] = [];

            for ($i = 1; $i <= 12; $i++) {
                $invoiceArr[$year][$i] = 0;
            }

            if (isset($invoiceTotalArray[$year])) {
                foreach ($invoiceTotalArray[$year] as $month => $values) {
                    $invoiceArr[$year][$month] = array_sum($values);
                }
            }
        }


            foreach ($array as $key => $categoryData) {

                $incomesum[] = Utility::revenuePaymentData($key , $categoryData, $request ,$yearList);

            }

            $revenueTotalArray = [];

            foreach ($incomes as $income) {
                $revenueTotalArray[$income->year][$income->month][] = $income->amount;
            }

            foreach ($yearList as $year) {
                $incomeArr[$year] = [];

                for ($i = 1; $i <= 12; $i++) {
                    $incomeArr[$year][$i] = 0;
                }

                if (isset($revenueTotalArray[$year])) {
                    foreach ($revenueTotalArray[$year] as $month => $values) {
                        $incomeArr[$year][$month] = array_sum($values);
                    }
                }
            }


            $chartIncomeArr = Utility::totalData($invoiceArr, $incomeArr, $request ,$yearList);


            $data['chartIncomeArr'] = $chartIncomeArr;
            $data['incomeArr'] = $incomesum;
            $data['invoiceArray'] = $invoicesum;
            $data['account'] = $account;
            $data['customer'] = $customer;
            $data['category'] = $category;
            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.income_summary', compact('filter', 'category'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function expenseSummary(Request $request)
    {
        if (\Auth::user()->can('expense report')) {
            $account = BankAccount::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('holder_name', 'id');
            $account->prepend('Select Account', '');
            $vender = Vender::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $vender->prepend('Select Vendor', '');
            $category = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->where('type', '=', 'expense')->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');

            if ($request->period === 'quarterly') {
                $month = [
                    'January-March',
                    'April-June',
                    'July-September',
                    'Octomber-December',
                ];
            } elseif ($request->period === 'half-yearly') {
                $month = [
                    'January-June',
                    'July-December',
                ];
            } elseif ($request->period === 'yearly') {
                $month = array_values(array_reverse($this->yearList()));
            } else {
                $month = $this->yearMonth();
            }
            $periods = Budget::$period;

            $data['monthList'] = $month;
            $data['yearList'] = $this->yearList();
            $data['periods'] = $periods;
            $filter['category'] = __('All');
            $filter['vender'] = __('All');

            if ($request->period === 'yearly') {
                $year = array_reverse($this->yearList());
                $yearList = [];
                foreach ($year as $value) {
                    $yearList[$value] = $value;
                }
                }
                else
                {
                $yearList[($request->year) ? $request->year : date('Y')] = ($request->year) ? $request->year : date('Y');
                }

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $data['currentYear'] = $year;

            //   -----------------------------------------PAYMENT EXPENSE ------------------------------------------------------------
            $expenses = Payment::selectRaw('sum(payments.amount) as amount,MONTH(date) as month,YEAR(date) as year,product_service_categories.name as category_id')->leftjoin('product_service_categories', 'payments.category_id', '=', 'product_service_categories.id');
            $expenses->where('payments.created_by', '=', \Auth::user()->creatorId());
            if ($request->period != 'yearly') {
                $expenses->whereRAW('YEAR(date) =?', [$year]);
            }

            if (!empty($request->category)) {
                $expenses->where('category_id', '=', $request->category);
                $cat = ProductServiceCategory::find($request->category);
                $filter['category'] = !empty($cat) ? $cat->name : '';
            }
            if (!empty($request->vender)) {
                $expenses->where('vender_id', '=', $request->vender);

                $vend = Vender::find($request->vender);
                $filter['vender'] = !empty($vend) ? $vend->name : '';
            }

            $expenses->groupBy('month', 'year', 'category_id');
            $expenses = $expenses->get();

            $tmpArray = [];
            foreach ($expenses as $expense) {
                $tmpArray[$expense->category_id][$expense->year][$expense->month] = $expense->amount;
            }
            $array = [];

            foreach ($tmpArray as $key => $yearData) {
                $array[$key] = [];

                foreach ($yearList as $targetYear) {
                    $array[$key][$targetYear] = [];

                    for ($i = 1; $i <= 12; $i++) {
                        $array[$key][$targetYear][$i] = 0;
                    }

                    if (isset($yearData[$targetYear])) {
                        foreach ($yearData[$targetYear] as $month => $value) {
                            $array[$key][$targetYear][$month] = (float) $value; // Convert the value to float if needed
                        }
                    }
                }
            }

            //     ------------------------------------BILL EXPENSE----------------------------------------------------

            $bills = Bill::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,product_service_categories.name as category_id,bill_id, bills.id')
                ->leftjoin('product_service_categories', 'bills.category_id', '=', 'product_service_categories.id')
                ->where('bills.created_by', \Auth::user()->creatorId())->where('status', '!=', 0);
            $bills->whereRAW('YEAR(send_date) =?', [$year]);

            if ($request->period != 'yearly') {
                $bills->whereRAW('YEAR(send_date) =?', [$year]);
                    }

            if (!empty($request->vender)) {
                $bills->where('vender_id', '=', $request->vender);
            }

            if (!empty($request->category)) {
                $bills->where('category_id', '=', $request->category);
            }
            $bills = $bills->get();

            $billTmpArray = [];
            foreach ($bills as $bill) {
                $billTmpArray[$bill->category_id][$bill->year][$bill->month][] = $bill->getTotal();
            }

            $billArray = [];

            foreach ($billTmpArray as $key => $yearData) {
                $billArray[$key] = [];

                foreach ($yearList as $targetYear) {
                    $billArray[$key][$targetYear] = [];

                    for ($i = 1; $i <= 12; $i++) {
                        $billArray[$key][$targetYear][$i] = 0;
                    }

                    if (isset($yearData[$targetYear])) {
                        foreach ($yearData[$targetYear] as $month => $values) {
                            if (is_array($values)) {
                                $sum = array_sum($values);
                                $billArray[$key][$targetYear][$month] = $sum;
                            } else {
                                $billArray[$key][$targetYear][$month] = (float) $values;
                            }
                        }
                    }
                }
            }

            $billsum = Utility::billInvoiceData($billArray, $request , $yearList);

            $billTotalArray = [];
            foreach ($bills as $bill) {
                $billTotalArray[$bill->year][$bill->month][] = $bill->getTotal();
            }

            $expenseArr = [];
            $billArr = [];
            $expensesum = [];

        foreach ($yearList as $year) {
            $billArr[$year] = [];

            for ($i = 1; $i <= 12; $i++) {
                $billArr[$year][$i] = 0;
            }

            if (isset($billTotalArray[$year])) {
                foreach ($billTotalArray[$year] as $month => $values) {
                    $billArr[$year][$month] = array_sum($values);
                }
            }
        }


        foreach ($array as $key => $categoryData) {

            $expensesum[] = Utility::revenuePaymentData($key , $categoryData, $request ,$yearList);
        }

        $paymentTotalArray = [];

        foreach ($expenses as $expense) {
            $paymentTotalArray[$expense->year][$expense->month][] = $expense->amount;
        }

        foreach ($yearList as $year) {
            $expenseArr[$year] = [];

            for ($i = 1; $i <= 12; $i++) {
                $expenseArr[$year][$i] = 0;
            }

            if (isset($paymentTotalArray[$year])) {
                foreach ($paymentTotalArray[$year] as $month => $values) {
                    $expenseArr[$year][$month] = array_sum($values);
                }
            }
        }

            $chartExpenseArr = Utility::totalData($billArr, $expenseArr, $request , $yearList);

            $data['chartExpenseArr'] = $chartExpenseArr;
            $data['expenseArr'] = $expensesum;
            $data['billArray'] = $billsum;
            $data['account'] = $account;
            $data['vender'] = $vender;
            $data['category'] = $category;

            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.expense_summary', compact('filter'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function incomeVsExpenseSummary(Request $request)
    {
        if (\Auth::user()->can('income vs expense report')) {
            $account = BankAccount::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('holder_name', 'id');
            $account->prepend('Select Account', '');
            $vender = Vender::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $vender->prepend('Select Vendor', '');
            $customer = Customer::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $customer->prepend('Select Customer', '');

            $category = ProductServiceCategory::where('created_by', '=', \Auth::user()->creatorId())->whereIn(
                'type', [
                    'income',
                    'expense',
                ]
            )->get()->pluck('name', 'id');
            $category->prepend('Select Category', '');

            if ($request->period === 'quarterly') {
                $month = [
                    'January-March',
                    'April-June',
                    'July-September',
                    'Octomber-December',
                ];
            } elseif ($request->period === 'half-yearly') {
                $month = [
                    'January-June',
                    'July-December',
                ];
            } elseif ($request->period === 'yearly') {
                $month = array_values(array_reverse($this->yearList()));

            } else {
                $month = $this->yearMonth();
            }

            $periods = Budget::$period;

            $data['monthList'] = $month;
            $data['yearList'] = $this->yearList();
            $data['periods'] = $periods;
            $filter['category'] = __('All');
            $filter['customer'] = __('All');
            $filter['vender'] = __('All');


            if ($request->period === 'yearly') {
                $year = array_reverse($this->yearList());
                $yearList = [];
                foreach ($year as $value) {
                    $yearList[$value] = $value;
                }
                }
                else
                {
                $yearList[($request->year) ? $request->year : date('Y')] = ($request->year) ? $request->year : date('Y');
                }

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $data['currentYear'] = $year;

            // ------------------------------TOTAL PAYMENT EXPENSE-----------------------------------------------------------
            $expensesData = Payment::selectRaw('sum(payments.amount) as amount,MONTH(date) as month,YEAR(date) as year');
            $expensesData->where('payments.created_by', '=', \Auth::user()->creatorId());
            if ($request->period != 'yearly') {
                $expensesData->whereRAW('YEAR(date) =?', [$year]);
            }

            if (!empty($request->category)) {
                $expensesData->where('category_id', '=', $request->category);
                $cat = ProductServiceCategory::find($request->category);
                $filter['category'] = !empty($cat) ? $cat->name : '';

            }
            if (!empty($request->vender)) {
                $expensesData->where('vender_id', '=', $request->vender);

                $vend = Vender::find($request->vender);
                $filter['vender'] = !empty($vend) ? $vend->name : '';
            }
            $expensesData->groupBy('month', 'year');
            $expensesData = $expensesData->get();

            // ------------------------------TOTAL BILL EXPENSE-----------------------------------------------------------

            $bills = Bill::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,category_id,bill_id,id')->where('created_by', \Auth::user()->creatorId())->where('status', '!=', 0);
            if ($request->period != 'yearly') {
                $bills->whereRAW('YEAR(send_date) =?', [$year]);
            }

            if (!empty($request->vender)) {
                $bills->where('vender_id', '=', $request->vender);

            }

            if (!empty($request->category)) {
                $bills->where('category_id', '=', $request->category);
            }

            $bills = $bills->get();

            $paymentTotalArray = [];
            foreach ($expensesData as $expense) {
                $paymentTotalArray[$expense->year][$expense->month][] = $expense->amount;
            }
            $expenseArr = [];

            foreach ($yearList as $year) {
                $expenseArr[$year] = [];

                for ($i = 1; $i <= 12; $i++) {
                    $expenseArr[$year][$i] = 0;
                }

                if (isset($paymentTotalArray[$year])) {
                    foreach ($paymentTotalArray[$year] as $month => $values) {
                        $expenseArr[$year][$month] = array_sum($values);
                    }
                }
            }

            $billTotalArray = [];
            foreach ($bills as $bill) {
                $billTotalArray[$bill->year][$bill->month][] = $bill->getTotal();
            }

            $billArr = [];
            $expensesum = [];

            foreach ($yearList as $year) {
                $billArr[$year] = [];

                for ($i = 1; $i <= 12; $i++) {
                    $billArr[$year][$i] = 0;
                }

                if (isset($billTotalArray[$year])) {
                    foreach ($billTotalArray[$year] as $month => $values) {
                        $billArr[$year][$month] = array_sum($values);
                    }
                }
            }


            $billsum = Utility::totalSum($billArr, $request , $yearList);

            $expensesum = Utility::totalSum($expenseArr, $request , $yearList);

            $chartExpenseArr = Utility::totalData($billArr, $expenseArr, $request , $yearList);

            // ------------------------------TOTAL REVENUE INCOME-----------------------------------------------------------

            $incomesData = Revenue::selectRaw('sum(revenues.amount) as amount,MONTH(date) as month,YEAR(date) as year');
            $incomesData->where('revenues.created_by', '=', \Auth::user()->creatorId());
            if ($request->period != 'yearly') {
                $incomesData->whereRAW('YEAR(date) =?', [$year]);
            }

            if (!empty($request->category)) {
                $incomesData->where('category_id', '=', $request->category);
            }
            if (!empty($request->customer)) {
                $incomesData->where('customer_id', '=', $request->customer);
                $cust = Customer::find($request->customer);
                $filter['customer'] = !empty($cust) ? $cust->name : '';
            }
            $incomesData->groupBy('month', 'year');
            $incomesData = $incomesData->get();

            // ------------------------------TOTAL INVOICE INCOME-----------------------------------------------------------
            $invoices = Invoice::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,category_id,invoice_id,id')
                ->where('created_by', \Auth::user()->creatorId())->where('status', '!=', 0);
                if ($request->period != 'yearly') {
                    $invoices->whereRAW('YEAR(send_date) =?', [$year]);
                }
            if (!empty($request->customer)) {
                $invoices->where('customer_id', '=', $request->customer);
            }
            if (!empty($request->category)) {
                $invoices->where('category_id', '=', $request->category);
            }
            $invoices = $invoices->get();

            $revenueTotalArray = [];
            foreach ($incomesData as $income) {
                $revenueTotalArray[$income->year][$income->month][] = $income->amount;
            }

            $incomeArr = [];

            foreach ($yearList as $year) {
                $incomeArr[$year] = [];

                for ($i = 1; $i <= 12; $i++) {
                    $incomeArr[$year][$i] = 0;
                }

                if (isset($revenueTotalArray[$year])) {
                    foreach ($revenueTotalArray[$year] as $month => $values) {
                        $incomeArr[$year][$month] = array_sum($values);
                    }
                }
            }

            $invoiceTotalArray = [];
            foreach ($invoices as $invoice) {
                $invoiceTotalArray[$invoice->year][$invoice->month][] = $invoice->getTotal();
            }

            $invoiceArr = [];
            $incomesum = [];

            foreach ($yearList as $year) {
                $invoiceArr[$year] = [];

                for ($i = 1; $i <= 12; $i++) {
                    $invoiceArr[$year][$i] = 0;
                }

                if (isset($invoiceTotalArray[$year])) {
                    foreach ($invoiceTotalArray[$year] as $month => $values) {
                        $invoiceArr[$year][$month] = array_sum($values);
                    }
                }
            }

            $invoicesum = Utility::totalSum($invoiceArr, $request , $yearList);

            $incomesum = Utility::totalSum($incomeArr, $request , $yearList);

            $chartIncomeArr = Utility::totalData($invoiceArr, $incomeArr, $request , $yearList);



            $profit = [];

                if (count($chartIncomeArr) === count($chartExpenseArr) && count($chartIncomeArr[0]) === count($chartExpenseArr[0])) {
                    foreach ($chartIncomeArr as $i => $values1) {
                        foreach ($values1 as $j => $value1) {
                            $profit[$i][$j] = $value1 - $chartExpenseArr[$i][$j];
                        }
                    }
                }


            $data['paymentExpenseTotal'] = $expensesum;
            $data['billExpenseTotal'] = $billsum;
            $data['revenueIncomeTotal'] = $incomesum;
            $data['invoiceIncomeTotal'] = $invoicesum;
            $data['profit'] = $profit;
            $data['account'] = $account;
            $data['vender'] = $vender;
            $data['customer'] = $customer;
            $data['category'] = $category;

            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.income_vs_expense_summary', compact('filter'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function taxSummary(Request $request)
    {

        if (\Auth::user()->can('tax report')) {
            $data['monthList'] = $month = $this->yearMonth();
            $data['yearList'] = $this->yearList();
            $data['taxList'] = $taxList = Tax::where('created_by', \Auth::user()->creatorId())->get();

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }

            $data['currentYear'] = $year;

            $invoiceProducts = InvoiceProduct::selectRaw('invoice_products.* ,MONTH(invoice_products.created_at) as month,YEAR(invoice_products.created_at) as year')->leftjoin('product_services', 'invoice_products.product_id', '=', 'product_services.id')->whereRaw('YEAR(invoice_products.created_at) =?', [$year])->where('product_services.created_by', '=', \Auth::user()->creatorId())->get();

            $incomeTaxesData = [];

            foreach ($invoiceProducts as $invoiceProduct) {
                $incomeTax = [];
                $getTaxData = Utility::getTaxData();

                if ($invoiceProduct->tax != null) {
                    foreach (explode(',', $invoiceProduct->tax) as $tax) {
                        $taxPrice = \Utility::taxRate($getTaxData[$tax]['rate'], $invoiceProduct->price, $invoiceProduct->quantity);

                        $itemName = $getTaxData[$tax]['name'];
                        $itemTax['name'] = $itemName;
                        $itemTax['rate'] = $getTaxData[$tax]['rate'] . '%';
                        $itemTax['price'] = ($taxPrice);

                        if (!isset($incomeTax[$itemName])) {
                            $incomeTax[$itemName] = 0;
                        }

                        $incomeTax[$itemName] += $itemTax['price'];
                    }
                }
                $incomeTaxesData[$invoiceProduct->month][] = $incomeTax;
            }

            $income = [];
            foreach ($incomeTaxesData as $month => $incomeTaxx) {
                $incomeTaxRecord = [];
                foreach ($incomeTaxx as $k => $record) {
                    foreach ($record as $incomeTaxName => $incomeTaxAmount) {
                        if (array_key_exists($incomeTaxName, $incomeTaxRecord)) {
                            $incomeTaxRecord[$incomeTaxName] += $incomeTaxAmount;
                        } else {
                            $incomeTaxRecord[$incomeTaxName] = $incomeTaxAmount;
                        }
                    }
                    $income['data'][$month] = $incomeTaxRecord;
                }

            }

            foreach ($income as $incomeMonth => $incomeTaxData) {
                $incomeData = [];
                for ($i = 1; $i <= 12; $i++) {
                    $incomeData[$i] = array_key_exists($i, $incomeTaxData) ? $incomeTaxData[$i] : 0;
                }

            }

            $incomes = [];
            if (isset($incomeData) && !empty($incomeData)) {
                foreach ($taxList as $taxArr) {
                    foreach ($incomeData as $month => $tax) {
                        if ($tax != 0) {
                            if (isset($tax[$taxArr->name])) {
                                $incomes[$taxArr->name][$month] = $tax[$taxArr->name];
                            } else {
                                $incomes[$taxArr->name][$month] = 0;
                            }
                        } else {
                            $incomes[$taxArr->name][$month] = 0;
                        }
                    }
                }
            }

            $billProducts = BillProduct::selectRaw('bill_products.* ,MONTH(bill_products.created_at) as month,YEAR(bill_products.created_at) as year')->leftjoin('product_services', 'bill_products.product_id', '=', 'product_services.id')->whereRaw('YEAR(bill_products.created_at) =?', [$year])->where('product_services.created_by', '=', \Auth::user()->creatorId())->get();

            $expenseTaxesData = [];
            foreach ($billProducts as $billProduct) {
                $billTax = [];

                $getTaxData = Utility::getTaxData();
                $taxesData = [];

                if ($billProduct->tax != null) {

                    foreach (explode(',', $billProduct->tax) as $tax) {
                        $taxPrice = \Utility::taxRate($getTaxData[$tax]['rate'], $billProduct->price, $billProduct->quantity);
                        $itemName = $getTaxData[$tax]['name'];
                        $itemTax['name'] = $itemName;
                        $itemTax['rate'] = $getTaxData[$tax]['rate'] . '%';
                        $itemTax['price'] = ($taxPrice);

                        if (!isset($billTax[$itemName])) {
                            $billTax[$itemName] = 0;
                        }
                        $billTax[$itemName] += $itemTax['price'];
                    }
                }
                $expenseTaxesData[$billProduct->month][] = $billTax;
            }

            $bill = [];
            foreach ($expenseTaxesData as $month => $billTaxx) {
                $billTaxRecord = [];
                foreach ($billTaxx as $k => $record) {
                    foreach ($record as $billTaxName => $billTaxAmount) {
                        if (array_key_exists($billTaxName, $billTaxRecord)) {
                            $billTaxRecord[$billTaxName] += $billTaxAmount;
                        } else {
                            $billTaxRecord[$billTaxName] = $billTaxAmount;
                        }
                    }
                    $bill['data'][$month] = $billTaxRecord;
                }

            }

            foreach ($bill as $billMonth => $billTaxData) {
                $billData = [];
                for ($i = 1; $i <= 12; $i++) {
                    $billData[$i] = array_key_exists($i, $billTaxData) ? $billTaxData[$i] : 0;
                }

            }
            $expenses = [];
            if (isset($billData) && !empty($billData)) {

                foreach ($taxList as $taxArr) {
                    foreach ($billData as $month => $tax) {
                        if ($tax != 0) {
                            if (isset($tax[$taxArr->name])) {
                                $expenses[$taxArr->name][$month] = $tax[$taxArr->name];
                            } else {
                                $expenses[$taxArr->name][$month] = 0;
                            }
                        } else {
                            $expenses[$taxArr->name][$month] = 0;
                        }
                    }

                }
            }

            $data['expenses'] = $expenses;
            $data['incomes'] = $incomes;

            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.tax_summary', compact('filter'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function yearMonth()
    {

        $month[] = __('January');
        $month[] = __('February');
        $month[] = __('March');
        $month[] = __('April');
        $month[] = __('May');
        $month[] = __('June');
        $month[] = __('July');
        $month[] = __('August');
        $month[] = __('September');
        $month[] = __('October');
        $month[] = __('November');
        $month[] = __('December');

        return $month;
    }

    public function yearList()
    {
        $starting_year = date('Y', strtotime('-5 year'));
        $ending_year = date('Y');

        foreach (range($ending_year, $starting_year) as $year) {
            $years[$year] = $year;
        }

        return $years;
    }

    public function invoiceSummary(Request $request)
    {

        if (\Auth::user()->can('invoice report')) {
            $filter['customer'] = __('All');
            $filter['status'] = __('All');

            $customer = Customer::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $customer->prepend('Select Customer', '');
            $status = Invoice::$statues;

            $invoices = Invoice::selectRaw('invoices.*,MONTH(send_date) as month,YEAR(send_date) as year');

            if ($request->status != '') {
                $invoices->where('status', $request->status);

                $filter['status'] = Invoice::$statues[$request->status];
            } else {
                $invoices->where('status', '!=', 0);
            }

            $invoices->where('created_by', '=', \Auth::user()->creatorId());

            if (!empty($request->start_month) && !empty($request->end_month)) {
                $start = strtotime($request->start_month);
                $end = strtotime($request->end_month);
            } else {
                $start = strtotime(date('Y-01'));
                $end = strtotime(date('Y-12'));
            }

            $invoices->where('send_date', '>=', date('Y-m-01', $start))->where('send_date', '<=', date('Y-m-t', $end));

            $filter['startDateRange'] = date('M-Y', $start);
            $filter['endDateRange'] = date('M-Y', $end);

            if (!empty($request->customer)) {
                $invoices->where('customer_id', $request->customer);
                $cust = Customer::find($request->customer);

                $filter['customer'] = !empty($cust) ? $cust->name : '';
            }

            $invoices = $invoices->with(['customer', 'category'])->get();

            $totalInvoice = 0;
            $totalDueInvoice = 0;
            $invoiceTotalArray = [];
            foreach ($invoices as $invoice) {
                $totalInvoice += $invoice->getTotal();
                $totalDueInvoice += $invoice->getDue();

                $invoiceTotalArray[$invoice->month][] = $invoice->getTotal();
            }
            $totalPaidInvoice = $totalInvoice - $totalDueInvoice;

            for ($i = 1; $i <= 12; $i++) {
                $invoiceTotal[] = array_key_exists($i, $invoiceTotalArray) ? array_sum($invoiceTotalArray[$i]) : 0;
            }

            $monthList = $month = $this->yearMonth();

            return view('report.invoice_report', compact('invoices', 'customer', 'status', 'totalInvoice', 'totalDueInvoice', 'totalPaidInvoice', 'invoiceTotal', 'monthList', 'filter'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function billSummary(Request $request)
    {
        if (\Auth::user()->can('bill report')) {

            $filter['vender'] = __('All');
            $filter['status'] = __('All');

            $vender = Vender::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $vender->prepend('Select Vendor', '');
            $status = Bill::$statues;

            $bills = Bill::selectRaw('bills.*,MONTH(send_date) as month,YEAR(send_date) as year');

            if ($request->status != '') {
                $bills->where('status', '=', $request->status);

                $filter['status'] = Bill::$statues[$request->status];
            } else {
                $bills->where('status', '!=', 0);
            }

            $bills->where('created_by', '=', \Auth::user()->creatorId());

            if (!empty($request->start_month) && !empty($request->end_month)) {
                $start = strtotime($request->start_month);
                $end = strtotime($request->end_month);
            } else {
                $start = strtotime(date('Y-01'));
                $end = strtotime(date('Y-12'));
            }

            $bills->where('send_date', '>=', date('Y-m-01', $start))->where('send_date', '<=', date('Y-m-t', $end));

            $filter['startDateRange'] = date('M-Y', $start);
            $filter['endDateRange'] = date('M-Y', $end);

            if (!empty($request->vender)) {
                $bills->where('vender_id', $request->vender);
                $vend = Vender::find($request->vender);

                $filter['vender'] = !empty($vend) ? $vend->name : '';
            }

            $bills = $bills->with(['vender', 'category'])->get();

            $totalBill = 0;
            $totalDueBill = 0;
            $billTotalArray = [];
            foreach ($bills as $bill) {
                $totalBill += $bill->getTotal();
                $totalDueBill += $bill->getDue();

                $billTotalArray[$bill->month][] = $bill->getTotal();
            }
            $totalPaidBill = $totalBill - $totalDueBill;

            for ($i = 1; $i <= 12; $i++) {
                $billTotal[] = array_key_exists($i, $billTotalArray) ? array_sum($billTotalArray[$i]) : 0;
            }

            $monthList = $month = $this->yearMonth();

            return view('report.bill_report', compact('bills', 'vender', 'status', 'totalBill', 'totalDueBill', 'totalPaidBill', 'billTotal', 'monthList', 'filter'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function accountStatement(Request $request)
    {
        if (\Auth::user()->can('statement report')) {

            $filter['account'] = __('All');
            $filter['type'] = __('Revenue');
            $reportData['revenues'] = '';
            $reportData['payments'] = '';
            $reportData['revenueAccounts'] = '';
            $reportData['paymentAccounts'] = '';

            $account = BankAccount::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('holder_name', 'id');
            $account->prepend('Select Account', '');

            $types = [
                'revenue' => __('Revenue'),
                'payment' => __('Payment'),
            ];

            if ($request->type == 'revenue' || !isset($request->type)) {

                $revenueAccounts = Revenue::select('bank_accounts.id', 'bank_accounts.holder_name', 'bank_accounts.bank_name')->leftjoin('bank_accounts', 'revenues.account_id', '=', 'bank_accounts.id')->groupBy('revenues.account_id')->selectRaw('sum(amount) as total')->where('revenues.created_by', '=', \Auth::user()->creatorId());

                $revenues = Revenue::where('revenues.created_by', '=', \Auth::user()->creatorId())->orderBy('id', 'desc');
            }

            if ($request->type == 'payment') {
                $paymentAccounts = Payment::select('bank_accounts.id', 'bank_accounts.holder_name', 'bank_accounts.bank_name')->leftjoin('bank_accounts', 'payments.account_id', '=', 'bank_accounts.id')->groupBy('payments.account_id')->selectRaw('sum(amount) as total')->where('payments.created_by', '=', \Auth::user()->creatorId());

                $payments = Payment::where('payments.created_by', '=', \Auth::user()->creatorId())->orderBy('id', 'desc');
            }

            if (!empty($request->start_month) && !empty($request->end_month)) {
                $start = strtotime($request->start_month);
                $end = strtotime($request->end_month);
            } else {
                $start = strtotime(date('Y-m'));
                $end = strtotime(date('Y-m', strtotime("-5 month")));
            }

            $currentdate = $start;
            while ($currentdate <= $end) {
                $data['month'] = date('m', $currentdate);
                $data['year'] = date('Y', $currentdate);

                if ($request->type == 'revenue' || !isset($request->type)) {
                    $revenues->Orwhere(
                        function ($query) use ($data) {
                            $query->whereMonth('date', $data['month'])->whereYear('date', $data['year']);
                            $query->where('revenues.created_by', '=', \Auth::user()->creatorId());
                        }
                    );

                    $revenueAccounts->Orwhere(
                        function ($query) use ($data) {
                            $query->whereMonth('date', $data['month'])->whereYear('date', $data['year']);
                            $query->where('revenues.created_by', '=', \Auth::user()->creatorId());
                        }
                    );
                }

                if ($request->type == 'payment') {
                    $paymentAccounts->Orwhere(
                        function ($query) use ($data) {
                            $query->whereMonth('date', $data['month'])->whereYear('date', $data['year']);
                            $query->where('payments.created_by', '=', \Auth::user()->creatorId());
                        }
                    );
                }

                $currentdate = strtotime('+1 month', $currentdate);
            }

            if (!empty($request->account)) {
                if ($request->type == 'revenue' || !isset($request->type)) {
                    $revenues->where('account_id', $request->account);
                    $revenues->where('revenues.created_by', '=', \Auth::user()->creatorId());
                    $revenueAccounts->where('account_id', $request->account);
                    $revenueAccounts->where('revenues.created_by', '=', \Auth::user()->creatorId());
                }

                if ($request->type == 'payment') {
                    $payments->where('account_id', $request->account);
                    $payments->where('payments.created_by', '=', \Auth::user()->creatorId());

                    $paymentAccounts->where('account_id', $request->account);
                    $paymentAccounts->where('payments.created_by', '=', \Auth::user()->creatorId());
                }

                $bankAccount = BankAccount::find($request->account);
                $filter['account'] = !empty($bankAccount) ? $bankAccount->holder_name . ' - ' . $bankAccount->bank_name : '';
                if ($bankAccount->holder_name == 'Cash') {
                    $filter['account'] = 'Cash';
                }

            }

            if ($request->type == 'revenue' || !isset($request->type)) {
                $reportData['revenues'] = $revenues->get();

                $revenueAccounts->where('revenues.created_by', '=', \Auth::user()->creatorId());
                $reportData['revenueAccounts'] = $revenueAccounts->get();

            }

            if ($request->type == 'payment') {
                $reportData['payments'] = $payments->get();

                $paymentAccounts->where('payments.created_by', '=', \Auth::user()->creatorId());
                $reportData['paymentAccounts'] = $paymentAccounts->get();
                $filter['type'] = __('Payment');
            }

            $filter['startDateRange'] = date('M-Y', $start);
            $filter['endDateRange'] = date('M-Y', $end);

            return view('report.statement_report', compact('reportData', 'account', 'types', 'filter'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function balanceSheet(Request $request, $view = '', $collapseview = 'expand')
    {
        if (!\Auth::user()->can('bill report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $currentStart = !empty($request->start_date) ? Carbon::parse($request->start_date) : Carbon::now()->startOfYear();
        $currentEnd = !empty($request->end_date) ? Carbon::parse($request->end_date) : Carbon::now();
        if ($currentStart->gt($currentEnd)) {
            [$currentStart, $currentEnd] = [$currentEnd->copy(), $currentStart->copy()];
        }

        $previousStart = $currentStart->copy()->subYear();
        $previousEnd = $currentEnd->copy()->subYear();
        $creatorId = \Auth::user()->creatorId();

        $lineMappings = [
            ['section' => 'Assets', 'label' => 'Fixed Assets', 'code_label' => '32', 'prefixes' => ['32']],
            ['section' => 'Assets', 'label' => 'Intangible Assets', 'code_label' => '33', 'prefixes' => ['33']],
            ['section' => 'Assets', 'label' => 'Inventory', 'code_label' => '21', 'prefixes' => ['21']],
            ['section' => 'Assets', 'label' => 'Clients', 'code_label' => '411', 'prefixes' => ['411']],
            ['section' => 'Assets', 'label' => 'Cash / Bank', 'code_label' => '11,12', 'prefixes' => ['11', '12']],
            ['section' => 'Liabilities', 'label' => 'Tax Payable', 'code_label' => '4437', 'prefixes' => ['4437']],
            ['section' => 'Equity', 'label' => 'Equity', 'code_label' => '59', 'prefixes' => ['59']],
        ];

        $rows = [];
        foreach ($lineMappings as $mapping) {
            $rows[] = [
                'section' => $mapping['section'],
                'label' => $mapping['label'],
                'code_label' => $mapping['code_label'],
                'current_year' => $this->sumBalanceByCodePrefixes($mapping['prefixes'], $currentStart, $currentEnd, $creatorId),
                'previous_year' => $this->sumBalanceByCodePrefixes($mapping['prefixes'], $previousStart, $previousEnd, $creatorId),
            ];
        }

        $assetsCurrent = collect($rows)->where('section', 'Assets')->sum('current_year');
        $assetsPrevious = collect($rows)->where('section', 'Assets')->sum('previous_year');
        $liabilitiesCurrent = collect($rows)->where('section', 'Liabilities')->sum('current_year');
        $liabilitiesPrevious = collect($rows)->where('section', 'Liabilities')->sum('previous_year');
        $equityCurrent = collect($rows)->where('section', 'Equity')->sum('current_year');
        $equityPrevious = collect($rows)->where('section', 'Equity')->sum('previous_year');

        $totals = [
            'assets' => ['current' => $assetsCurrent, 'previous' => $assetsPrevious],
            'liabilities' => ['current' => $liabilitiesCurrent, 'previous' => $liabilitiesPrevious],
            'equity' => ['current' => $equityCurrent, 'previous' => $equityPrevious],
            'equity_liabilities' => [
                'current' => $equityCurrent + $liabilitiesCurrent,
                'previous' => $equityPrevious + $liabilitiesPrevious,
            ],
            'difference' => [
                'current' => $assetsCurrent - ($equityCurrent + $liabilitiesCurrent),
                'previous' => $assetsPrevious - ($equityPrevious + $liabilitiesPrevious),
            ],
        ];

        $settings = Utility::settings();
        $header = [
            'company_name' => $settings['company_name'] ?? (\Auth::user()->name ?? config('app.name')),
            'nuit' => $settings['vat_number'] ?? '',
            'currency' => $settings['site_currency'] ?? 'USD',
        ];

        $filter = [
            'startDateRange' => $currentStart->format('Y-m-d'),
            'endDateRange' => $currentEnd->format('Y-m-d'),
            'previousStartDateRange' => $previousStart->format('Y-m-d'),
            'previousEndDateRange' => $previousEnd->format('Y-m-d'),
            'currentYear' => (string) Carbon::now()->year,
            'previousYear' => (string) (Carbon::now()->year - 1),
        ];

        return view('report.balance_sheet', compact('filter', 'collapseview', 'rows', 'totals', 'header'));
    }

    private function sumBalanceByCodePrefixes(array $prefixes, Carbon $start, Carbon $end, int $creatorId): float
    {
        $query = TransactionLines::select(\DB::raw('COALESCE(SUM(transaction_lines.credit - transaction_lines.debit),0) as balance'))
            ->join('chart_of_accounts', 'transaction_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('transaction_lines.created_by', $creatorId)
            ->whereBetween('transaction_lines.date', [$start->toDateString(), $end->toDateString()]);

        $query->where(function ($q) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $q->orWhere('chart_of_accounts.code', 'like', $prefix . '%');
            }
        });

        return (float) ($query->value('balance') ?? 0);
    }

    public function ledgerSummary(Request $request, $account = '')
    {

        if (\Auth::user()->can('ledger report')) {

            if (!empty($request->start_date) && !empty($request->end_date)) {
                $start = $request->start_date;
                $end = $request->end_date;
            } else {
                $start = date('Y-m-01');
                $end = date('Y-m-t');
            }
            if (!empty($request->account)) {
                $chart_accounts = ChartOfAccount::where('id', $request->account)->where('created_by', \Auth::user()->creatorId())->get();
                // $accounts = ChartOfAccount::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
                $accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.parent')
                ->where('parent', '=', 0)
                ->where('created_by', \Auth::user()->creatorId())->get()
                ->toarray();

            } else {
                $chart_accounts = ChartOfAccount::where('created_by', \Auth::user()->creatorId())->get();
                // $accounts = $chart_accounts->pluck('name', 'id');
                $accounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.parent')
                ->where('parent', '=', 0)
                ->where('created_by', \Auth::user()->creatorId())->get()
                ->toarray();
            }

            $subAccounts = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_account_parents.account');
            $subAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $subAccounts->where('chart_of_accounts.parent', '!=', 0);
            $subAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $subAccounts = $subAccounts->get()->toArray();

            $balance = 0;
            $debit = 0;
            $credit = 0;
            $filter['balance'] = $balance;
            $filter['credit'] = $credit;
            $filter['debit'] = $debit;
            $filter['startDateRange'] = $start;
            $filter['endDateRange'] = $end;
            return view('report.ledger_summary', compact('filter', 'chart_accounts', 'accounts', 'subAccounts'));

        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }


    public function trialBalanceSummary(Request $request, $view = "expand")
    {
        if (\Auth::user()->can('trial balance report')) {

            if (!empty($request->start_date) && !empty($request->end_date)) {
                $start = $request->start_date;
                $end = $request->end_date;
            } else {
                $start = date('Y-01-01');
                $end = date('Y-m-d', strtotime('+1 day'));
            }
            $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->get();
            $totalAccounts = [];
            $totalAccount = [];
            foreach ($types as $type) {

                // $type->id = 4;
                $parentAccounts = ChartOfAccountParent::where('type', $type->id)->where('created_by', \Auth::user()->creatorId())->get();

                $totalParentAccountArray = [];
                if ($parentAccounts->isNotEmpty()) {
                    foreach ($parentAccounts as $parentAccount) {
                        $totalArray = [];
                        $parentAccountArray = [];
                        $parentAccountArrayTotal = [];

                        $parentAccs = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                        $parentAccs->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                        $parentAccs->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                        $parentAccs->where('chart_of_accounts.type', $type->id);
                        $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                        $parentAccs->where('transaction_lines.created_by', \Auth::user()->creatorId());
                        $parentAccs->where('transaction_lines.date', '>=', $start);
                        $parentAccs->where('transaction_lines.date', '<=', $end);
                        $parentAccs->groupBy('account_id');
                        $parentAccs = $parentAccs->get()->toArray();

                        $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                        $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                        $accounts->where('chart_of_accounts.type', $type->id);
                        $accounts->where('chart_of_accounts.parent', $parentAccount->id);
                        $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                        $accounts->where('transaction_lines.date', '>=', $start);
                        $accounts->where('transaction_lines.date', '<=', $end);
                        $accounts->groupBy('account_id');
                        $accounts = $accounts->get()->toArray();

                        if ($parentAccs == [] && $accounts != []) {

                            $parentAccs = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('0 as totalDebit'), \DB::raw('0 as totalCredit'));
                            $parentAccs->leftjoin('chart_of_account_parents', 'chart_of_accounts.id', 'chart_of_account_parents.account');
                            $parentAccs->where('chart_of_accounts.type', $type->id);
                            $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                            $parentAccs->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                            $parentAccs = $parentAccs->get()->toArray();
                        } elseif ($parentAccs != [] && $accounts == []) {
                            $parentAccs = [];

                        }

                        $parenttotalBalance = 0;
                        $parentcreditTotal = 0;
                        $parenntdebitTotal = 0;
                        $parenttotalAmount = 0;

                        foreach ($parentAccs as $account) {
                            $Balance = $account['totalCredit'] - $account['totalDebit'];
                            $parenttotalBalance += $Balance;

                            $data = [
                                'account_id' => $account['id'],
                                'account_code' => $account['code'],
                                'account_name' => $account['name'],
                                'account' => 'parent',
                                'totalCredit' => $account['totalCredit'],
                                'totalDebit' => $account['totalDebit'],
                            ];

                            $parentAccountArray[] = $data;
                            $parentcreditTotal += $data['totalCredit'];
                            $parenntdebitTotal += $data['totalDebit'];
                        }

                        foreach ($accounts as $account) {
                            $Balance = $account['totalCredit'] - $account['totalDebit'];

                            if ($Balance != 0) {
                                $data = [
                                    'account_id' => $account['id'],
                                    'account_code' => $account['code'],
                                    'account_name' => $account['name'],
                                    'account' => 'subAccount',
                                    'totalCredit' => $account['totalCredit'],
                                    'totalDebit' => $account['totalDebit'],
                                ];

                                $parentAccountArray[] = $data;
                                $parentcreditTotal += $data['totalCredit'];
                                $parenntdebitTotal += $data['totalDebit'];
                            }
                        }

                        if (!empty($parentAccountArray)) {

                            $dataTotal = [
                                'account_id' => $parentAccount->account,
                                'account_code' => '',
                                'account' => 'parentTotal',
                                'account_name' => 'Total ' . $parentAccount->name,
                                'totalCredit' => $parentcreditTotal,
                                'totalDebit' => $parenntdebitTotal,
                            ];

                            $parentAccountArrayTotal[] = $dataTotal;
                        }

                        if ($parentAccountArray != []) {
                            $totalArray = array_merge($parentAccountArray, $parentAccountArrayTotal);
                            $totalParentAccountArray[] = $totalArray;
                        }
                    }
                }

                if ($totalParentAccountArray != []) {
                    $accounts = TransactionLines::select('chart_of_accounts.id as account_id', 'chart_of_accounts.code as account_code', 'chart_of_accounts.name as account_name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $accounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.name', 'chart_of_account_parents.name');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('chart_of_account_parents.account');
                    $accounts->where('chart_of_accounts.parent', '=', 'chart_of_account_parents.id');
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();
                } else {
                    $accounts = TransactionLines::select('chart_of_accounts.id as account_id', 'chart_of_accounts.code as account_code', 'chart_of_accounts.name as account_name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();
                }

                $name = $type->name;
                if (isset($totalAccount[$name])) {
                    $totalAccount[$name]["totalCredit"] += $accounts["totalCredit"];
                    $totalAccount[$name]["totalDebit"] += $accounts["totalDebit"];
                } else {
                    $totalAccount[$name] = $accounts;
                }
                if ($totalParentAccountArray != []) {
                    $totalAccount[$name] = array_merge_recursive($totalAccount[$name], $totalParentAccountArray[0]);
                }
            }

            foreach ($totalAccount as $category => $entries) {
                foreach ($entries as $entry) {
                    $name = $entry['account_name'];
                    if (!isset($totalAccounts[$category][$name])) {
                        $totalAccounts[$category][$name] = [
                            'account_id' => $entry['account_id'],
                            'account_code' => $entry['account_code'],
                            'account_name' => $name,
                            'account' => isset($entry['account']) ? $entry['account'] : '',
                            'totalDebit' => 0,
                            'totalCredit' => 0,
                        ];
                    }
                    if ($entry['totalDebit'] < 0) {
                        $totalAccounts[$category][$name]['totalDebit'] += 0;
                        $totalAccounts[$category][$name]['totalCredit'] += -$entry['totalDebit'];
                    } else {
                        $totalAccounts[$category][$name]['totalDebit'] += $entry['totalDebit'];
                        $totalAccounts[$category][$name]['totalCredit'] += $entry['totalCredit'];
                    }
                }
            }

            $filter['startDateRange'] = $start;
            $filter['endDateRange'] = $end;
            return view('report.trial_balance', compact('filter', 'totalAccounts', 'view'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }
    public function leave(Request $request)
    {

        if (\Auth::user()->can('manage report')) {

            $branch = Branch::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $branch->prepend('Select Branch', '');

            $department = Department::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $department->prepend('Select Department', '');

            $filterYear['branch'] = __('All');
            $filterYear['department'] = __('All');
            $filterYear['type'] = __('Monthly');
            $filterYear['dateYearRange'] = date('M-Y');
            $employees = Employee::where('created_by', \Auth::user()->creatorId());
            if (!empty($request->branch)) {
                $employees->where('branch_id', $request->branch);
                $filterYear['branch'] = !empty(Branch::find($request->branch)) ? Branch::find($request->branch)->name : '';
            }
            if (!empty($request->department)) {
                $employees->where('department_id', $request->department);
                $filterYear['department'] = !empty(Department::find($request->department)) ? Department::find($request->department)->name : '';
            }

            $employees = $employees->get();

            $leaves = [];
            $totalApproved = $totalReject = $totalPending = 0;
            foreach ($employees as $employee) {

                $employeeLeave['id'] = $employee->id;
                $employeeLeave['employee_id'] = $employee->employee_id;
                $employeeLeave['employee'] = $employee->name;

                $approved = Leave::where('employee_id', $employee->id)->where('status', 'Approved');
                $reject = Leave::where('employee_id', $employee->id)->where('status', 'Reject');
                $pending = Leave::where('employee_id', $employee->id)->where('status', 'Pending');

                if ($request->type == 'monthly' && !empty($request->month)) {
                    $month = date('m', strtotime($request->month));
                    $year = date('Y', strtotime($request->month));

                    $approved->whereMonth('applied_on', $month)->whereYear('applied_on', $year);
                    $reject->whereMonth('applied_on', $month)->whereYear('applied_on', $year);
                    $pending->whereMonth('applied_on', $month)->whereYear('applied_on', $year);

                    $filterYear['dateYearRange'] = date('M-Y', strtotime($request->month));
                    $filterYear['type'] = __('Monthly');

                } elseif (!isset($request->type)) {
                    $month = date('m');
                    $year = date('Y');
                    $monthYear = date('Y-m');

                    $approved->whereMonth('applied_on', $month)->whereYear('applied_on', $year);
                    $reject->whereMonth('applied_on', $month)->whereYear('applied_on', $year);
                    $pending->whereMonth('applied_on', $month)->whereYear('applied_on', $year);

                    $filterYear['dateYearRange'] = date('M-Y', strtotime($monthYear));
                    $filterYear['type'] = __('Monthly');
                }

                if ($request->type == 'yearly' && !empty($request->year)) {
                    $approved->whereYear('applied_on', $request->year);
                    $reject->whereYear('applied_on', $request->year);
                    $pending->whereYear('applied_on', $request->year);

                    $filterYear['dateYearRange'] = $request->year;
                    $filterYear['type'] = __('Yearly');
                }

                $approved = $approved->count();
                $reject = $reject->count();
                $pending = $pending->count();

                $totalApproved += $approved;
                $totalReject += $reject;
                $totalPending += $pending;

                $employeeLeave['approved'] = $approved;
                $employeeLeave['reject'] = $reject;
                $employeeLeave['pending'] = $pending;

                $leaves[] = $employeeLeave;
            }

            $starting_year = date('Y', strtotime('-5 year'));
            $ending_year = date('Y', strtotime('+5 year'));

            $filterYear['starting_year'] = $starting_year;
            $filterYear['ending_year'] = $ending_year;

            $filter['totalApproved'] = $totalApproved;
            $filter['totalReject'] = $totalReject;
            $filter['totalPending'] = $totalPending;

            return view('report.leave', compact('department', 'branch', 'leaves', 'filterYear', 'filter'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function employeeLeave(Request $request, $employee_id, $status, $type, $month, $year)
    {
        if (\Auth::user()->can('manage report')) {
            $leaveTypes = LeaveType::where('created_by', \Auth::user()->creatorId())->get();
            $leaves = [];
            foreach ($leaveTypes as $leaveType) {
                $leave = new Leave();
                $leave->title = $leaveType->title;
                $totalLeave = Leave::where('employee_id', $employee_id)->where('status', $status)->where('leave_type_id', $leaveType->id);
                if ($type == 'yearly') {
                    $totalLeave->whereYear('applied_on', $year);
                } else {
                    $m = date('m', strtotime($month));
                    $y = date('Y', strtotime($month));

                    $totalLeave->whereMonth('applied_on', $m)->whereYear('applied_on', $y);
                }
                $totalLeave = $totalLeave->count();

                $leave->total = $totalLeave;
                $leaves[] = $leave;
            }

            $leaveData = Leave::where('employee_id', $employee_id)->where('status', $status);
            if ($type == 'yearly') {
                $leaveData->whereYear('applied_on', $year);
            } else {
                $m = date('m', strtotime($month));
                $y = date('Y', strtotime($month));

                $leaveData->whereMonth('applied_on', $m)->whereYear('applied_on', $y);
            }

            $leaveData = $leaveData->get();

            return view('report.leaveShow', compact('leaves', 'leaveData'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function monthlyAttendance(Request $request)
    {
        if (\Auth::user()->can('manage report')) {
            $branch = Branch::where('created_by', '=', \Auth::user()->creatorId())->get();
            $department = Department::where('created_by', '=', \Auth::user()->creatorId())->get();

            $data['branch'] = __('All');
            $data['department'] = __('All');

            $employees = Employee::select('id', 'name');
            if (!empty($request->employee_id) && $request->employee_id[0] != 0) {
                $employees->whereIn('id', $request->employee_id);
            }
            $employees = $employees->where('created_by', \Auth::user()->creatorId());

            if (!empty($request->branch)) {
                $employees->where('branch_id', $request->branch);
                $data['branch'] = !empty(Branch::find($request->branch)) ? Branch::find($request->branch)->name : '';
            }

            if (!empty($request->department)) {
                $employees->where('department_id', $request->department);
                $data['department'] = !empty(Department::find($request->department)) ? Department::find($request->department)->name : '';
            }

            $employees = $employees->get()->pluck('name', 'id');

            if (!empty($request->month)) {
                $currentdate = strtotime($request->month);
                $month = date('m', $currentdate);
                $year = date('Y', $currentdate);
                $curMonth = date('M-Y', strtotime($request->month));

            } else {
                $month = date('m');
                $year = date('Y');
                $curMonth = date('M-Y', strtotime($year . '-' . $month));
            }

            $num_of_days = date('t', mktime(0, 0, 0, $month, 1, $year));
            for ($i = 1; $i <= $num_of_days; $i++) {
                $dates[] = str_pad($i, 2, '0', STR_PAD_LEFT);
            }

            $employeesAttendance = [];
            $totalPresent = $totalLeave = $totalEarlyLeave = 0;
            $ovetimeHours = $overtimeMins = $earlyleaveHours = $earlyleaveMins = $lateHours = $lateMins = 0;
            foreach ($employees as $id => $employee) {
                $attendances['name'] = $employee;

                foreach ($dates as $date) {
                    $dateFormat = $year . '-' . $month . '-' . $date;

                    if ($dateFormat <= date('Y-m-d')) {
                        $employeeAttendance = AttendanceEmployee::where('employee_id', $id)->where('date', $dateFormat)->first();

                        if (!empty($employeeAttendance) && $employeeAttendance->status == 'Present') {
                            $attendanceStatus[$date] = 'P';
                            $totalPresent += 1;

                            if ($employeeAttendance->overtime > 0) {
                                $ovetimeHours += date('h', strtotime($employeeAttendance->overtime));
                                $overtimeMins += date('i', strtotime($employeeAttendance->overtime));
                            }

                            if ($employeeAttendance->early_leaving > 0) {
                                $earlyleaveHours += date('h', strtotime($employeeAttendance->early_leaving));
                                $earlyleaveMins += date('i', strtotime($employeeAttendance->early_leaving));
                            }

                            if ($employeeAttendance->late > 0) {
                                $lateHours += date('h', strtotime($employeeAttendance->late));
                                $lateMins += date('i', strtotime($employeeAttendance->late));
                            }

                        } elseif (!empty($employeeAttendance) && in_array($employeeAttendance->status, ['Leave', 'Absent'], true)) {
                            $attendanceStatus[$date] = 'A';
                            $totalLeave += 1;
                        } else {
                            $attendanceStatus[$date] = '';
                        }
                    } else {
                        $attendanceStatus[$date] = '';
                    }

                }
                $attendances['status'] = $attendanceStatus;
                $employeesAttendance[] = $attendances;
            }

            $totalOverTime = $ovetimeHours + ($overtimeMins / 60);
            $totalEarlyleave = $earlyleaveHours + ($earlyleaveMins / 60);
            $totalLate = $lateHours + ($lateMins / 60);

            $data['totalOvertime'] = $totalOverTime;
            $data['totalEarlyLeave'] = $totalEarlyleave;
            $data['totalLate'] = $totalLate;
            $data['totalPresent'] = $totalPresent;
            $data['totalLeave'] = $totalLeave;
            $data['curMonth'] = $curMonth;

            return view('report.monthlyAttendance', compact('employeesAttendance', 'branch', 'department', 'dates', 'data'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function payroll(Request $request)
    {

        if (\Auth::user()->can('manage report')) {
            $branch = Branch::where('created_by', '=', \Auth::user()->creatorId())->get();
            $department = Department::where('created_by', '=', \Auth::user()->creatorId())->get();
            $employees = Employee::select('id', 'name');
            if (!empty($request->employee_id) && $request->employee_id[0] != 0) {
                $employees->where('id', $request->employee_id);
            }
            $employees = $employees->where('created_by', \Auth::user()->creatorId());

            $data['branch'] = __('All');
            $data['department'] = __('All');
            $filterYear['branch'] = __('All');
            $filterYear['department'] = __('All');
            $filterYear['type'] = __('Monthly');
            $filterYear['dateYearRange'] = '';

            $payslips = PaySlip::select('pay_slips.*', 'employees.name')->leftjoin('employees', 'pay_slips.employee_id', '=', 'employees.id')->where('pay_slips.created_by', \Auth::user()->creatorId());

            if ($request->type == 'monthly' && !empty($request->month)) {

                $payslips->where('salary_month', $request->month);

                $filterYear['dateYearRange'] = date('M-Y', strtotime($request->month));
                $filterYear['type'] = __('Monthly');
            } elseif (!isset($request->type)) {
                $month = date('Y-m');

                $payslips->where('salary_month', $month);

                $filterYear['dateYearRange'] = date('M-Y', strtotime($month));
                $filterYear['type'] = __('Monthly');
            }

            if ($request->type == 'yearly' && !empty($request->year)) {
                $startMonth = $request->year . '-01';
                $endMonth = $request->year . '-12';
                $payslips->where('salary_month', '>=', $startMonth)->where('salary_month', '<=', $endMonth);

                $filterYear['dateYearRange'] = $request->year;
                $filterYear['type'] = __('Yearly');
            }

            if (!empty($request->branch)) {
                $payslips->where('employees.branch_id', $request->branch);

                $filterYear['branch'] = !empty(Branch::find($request->branch)) ? Branch::find($request->branch)->name : '';
            }

            if (!empty($request->department)) {

                $payslips->where('employees.department_id', $request->department);

                $filterYear['department'] = !empty(Department::find($request->department)) ? Department::find($request->department)->name : '';
            }

            $employees = $employees->get()->pluck('name', 'id')->toArray();

            $payslips = $payslips->whereIn('name', $employees)->with(['employees'])->get();

            $totalBasicSalary = $totalNetSalary = $totalAllowance = $totalCommision = $totalLoan = $totalSaturationDeduction = $totalOtherPayment = $totalOverTime = 0;

            foreach ($payslips as $payslip) {
                $totalBasicSalary += $payslip->basic_salary;
                $totalNetSalary += $payslip->net_payble;

                $allowances = json_decode($payslip->allowance);
                foreach ($allowances as $allowance) {
                    $totalAllowance += $allowance->amount;

                }

                $commisions = json_decode($payslip->commission);
                foreach ($commisions as $commision) {
                    $totalCommision += $commision->amount;

                }

                $loans = json_decode($payslip->loan);
                foreach ($loans as $loan) {
                    $totalLoan += $loan->amount;
                }

                $saturationDeductions = json_decode($payslip->saturation_deduction);
                foreach ($saturationDeductions as $saturationDeduction) {
                    $totalSaturationDeduction += $saturationDeduction->amount;
                }

                $otherPayments = json_decode($payslip->other_payment);
                foreach ($otherPayments as $otherPayment) {
                    $totalOtherPayment += $otherPayment->amount;
                }

                $overtimes = json_decode($payslip->overtime);
                foreach ($overtimes as $overtime) {
                    $days = $overtime->number_of_days;
                    $hours = $overtime->hours;
                    $rate = $overtime->rate;

                    $totalOverTime += ($rate * $hours) * $days;
                }

            }

            $filterData['totalBasicSalary'] = $totalBasicSalary;
            $filterData['totalNetSalary'] = $totalNetSalary;
            $filterData['totalAllowance'] = $totalAllowance;
            $filterData['totalCommision'] = $totalCommision;
            $filterData['totalLoan'] = $totalLoan;
            $filterData['totalSaturationDeduction'] = $totalSaturationDeduction;
            $filterData['totalOtherPayment'] = $totalOtherPayment;
            $filterData['totalOverTime'] = $totalOverTime;

            $starting_year = date('Y', strtotime('-5 year'));
            $ending_year = date('Y', strtotime('+5 year'));

            $filterYear['starting_year'] = $starting_year;
            $filterYear['ending_year'] = $ending_year;

            return view('report.payroll', compact('payslips', 'filterData', 'branch', 'department', 'filterYear'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    //branch wise department get in Payroll report
    public function getPayrollDepartment(Request $request)
    {
        if ($request->branch_id == 0) {
            $departments = Department::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id')->toArray();
        } else {
            $departments = Department::where('created_by', '=', \Auth::user()->creatorId())->where('branch_id', $request->branch_id)->get()->pluck('name', 'id')->toArray();
        }

        return response()->json($departments);
    }

    public function getPayrollEmployee(Request $request)
    {
        if (!$request->department_id) {
            $employees = Employee::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id')->toArray();
        } else {
            $employees = Employee::where('created_by', '=', \Auth::user()->creatorId())->where('department_id', $request->department_id)->get()->pluck('name', 'id')->toArray();
        }
        return response()->json($employees);
    }

    public function exportCsv($filter_month, $branch, $department)
    {

        $data['branch'] = __('All');
        $data['department'] = __('All');
        $employees = Employee::select('id', 'name')->where('created_by', \Auth::user()->creatorId());
        if ($branch != 0) {
            $employees->where('branch_id', $branch);
            $data['branch'] = !empty(Branch::find($branch)) ? Branch::find($branch)->name : '';
        }

        if ($department != 0) {
            $employees->where('department_id', $department);
            $data['department'] = !empty(Department::find($department)) ? Department::find($department)->name : '';
        }

        $employees = $employees->get()->pluck('name', 'id');

        $currentdate = strtotime($filter_month);
        $month = date('m', $currentdate);
        $year = date('Y', $currentdate);
        $data['curMonth'] = date('M-Y', strtotime($filter_month));

        $fileName = $data['branch'] . ' ' . __('Branch') . ' ' . $data['curMonth'] . ' ' . __('Attendance Report of') . ' ' . $data['department'] . ' ' . __('Department') . ' ' . '.csv';

        $num_of_days = date('t', mktime(0, 0, 0, $month, 1, $year));
        for ($i = 1; $i <= $num_of_days; $i++) {
            $dates[] = str_pad($i, 2, '0', STR_PAD_LEFT);
        }

        foreach ($employees as $id => $employee) {
            $attendances['name'] = $employee;

            foreach ($dates as $date) {

                $dateFormat = $year . '-' . $month . '-' . $date;

                if ($dateFormat <= date('Y-m-d')) {
                    $employeeAttendance = AttendanceEmployee::where('employee_id', $id)->where('date', $dateFormat)->first();

                    if (!empty($employeeAttendance) && $employeeAttendance->status == 'Present') {
                        $attendanceStatus[$date] = 'P';
                    } elseif (!empty($employeeAttendance) && in_array($employeeAttendance->status, ['Leave', 'Absent'], true)) {
                        $attendanceStatus[$date] = 'A';
                    } else {
                        $attendanceStatus[$date] = '-';
                    }

                } else {
                    $attendanceStatus[$date] = '-';
                }
                $attendances[$date] = $attendanceStatus[$date];
            }

            $employeesAttendance[] = $attendances;
        }

        $headers = array(
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0",
        );

        $emp = array(
            'employee',
        );

        $columns = array_merge($emp, $dates);

        $callback = function () use ($employeesAttendance, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($employeesAttendance as $attendance) {
                fputcsv($file, str_replace('"', '', array_values($attendance)));
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function productStock(Request $request)
    {
        if (\Auth::user()->can('stock report')) {
            $stocks = StockReport::with(['product'])->where('created_by', '=', \Auth::user()->creatorId())->orderByDesc('id')->get();
            foreach ($stocks as $stock) {
                $stock->stock_identifiers = null;
                if ($stock->type === 'invoice' && ! empty($stock->type_id)) {
                    $lines = InvoiceProduct::where('invoice_id', $stock->type_id)
                        ->where('product_id', $stock->product_id)
                        ->whereNotNull('identifiers')
                        ->where('identifiers', '!=', '')
                        ->pluck('identifiers');
                    if ($lines->isNotEmpty()) {
                        $stock->stock_identifiers = $lines->implode("\n");
                    }
                }
            }

            return view('report.product_stock_report', compact('stocks'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    //for export in account statement report
    public function export()
    {
        $name = 'account_statement' . date('Y-m-d i:h:s');
        $data = Excel::download(new AccountStatementExport(), $name . '.xlsx');

        return $data;
    }
    // for export in product stock report
    public function stock_export()
    {
        $name = 'Product_Stock' . date('Y-m-d i:h:s');
        $data = Excel::download(new ProductStockExport(), $name . '.xlsx');

        return $data;
    }

    // for export in payroll report
    public function PayrollReportExport(Request $request)
    {
        $name = 'Payroll_' . date('Y-m-d i:h:s');
        $data = \Excel::download(new PayrollExport(), $name . '.xlsx');

        return $data;
    }

    // for export in leave report
    public function LeaveReportExport()
    {
        $name = 'leave_' . date('Y-m-d i:h:s');
        $data = \Excel::download(new LeaveReportExport(), $name . '.xlsx');

        return $data;
    }

    //branch wise department get in monthly-attendance report
    public function getdepartment(Request $request)
    {
        if ($request->branch_id == 0) {
            $departments = Department::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id')->toArray();
        } else {
            $departments = Department::where('created_by', '=', \Auth::user()->creatorId())->where('branch_id', $request->branch_id)->get()->pluck('name', 'id')->toArray();
        }

        return response()->json($departments);
    }

    public function getemployee(Request $request)
    {
        if (!$request->department_id) {
            $employees = Employee::where('created_by', '=', \Auth::user()->creatorId())->get()->pluck('name', 'id')->toArray();
        } else {
            $employees = Employee::where('created_by', '=', \Auth::user()->creatorId())->where('department_id', $request->department_id)->get()->pluck('name', 'id')->toArray();
        }
        return response()->json($employees);
    }

    public function leadreport(Request $request)
    {
        $user = \Auth::user();
        $leads = Lead::orderBy('id');
        $leads->where('created_by', \Auth::user()->creatorId());

        $user_week_lead = Lead::orderBy('created_at')->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->get()->groupBy(function ($item) {
            return $item->created_at->format('Y-m-d');
        });
        $carbaoDay = Carbon::now()->startOfWeek();

        $weeks = [];
        for ($i = 0; $i < 7; $i++) {
            $weeks[$carbaoDay->startOfWeek()->addDay($i)->format('Y-m-d')] = 0;
        }
        foreach ($user_week_lead as $name => $leads) {
            $weeks[$name] = $leads->count();
        }

        $devicearray = [];
        $devicearray['label'] = [];
        $devicearray['data'] = [];

        foreach ($weeks as $name => $leads) {
            $devicearray['label'][] = Carbon::parse($name)->format('l');
            $devicearray['data'][] = $leads;
        }
        $leads = Lead::where('created_by', '=', \Auth::user()->creatorId())->get();

        $lead_source = Source::where('created_by', \Auth::user()->id)->get();

        $leadsourceName = [];
        $leadsourceeData = [];
        foreach ($lead_source as $lead_source_data) {
            $lead_source = lead::where('created_by', \Auth::user()->id)->where('sources', $lead_source_data->id)->count();
            $leadsourceName[] = $lead_source_data->name;
            $leadsourceeData[] = $lead_source;
        }

        // monthly report

        $labels = [];
        $data = [];

        if (!empty($request->start_month) && !empty($request->end_month)) {
            $start = strtotime($request->start_month);
            $end = strtotime($request->end_month);
        } else {
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
        }

        $leads = Lead::orderBy('id');
        $leads->where('date', '>=', date('Y-m-01', $start))->where('date', '<=', date('Y-m-t', $end));
        $leads->where('created_by', \Auth::user()->creatorId());
        $leads = $leads->get();

        $currentdate = $start;
        while ($currentdate <= $end) {
            $month = date('m', $currentdate);
            $year = date('Y');

            if (!empty($request->start_month)) {
                $leadFilter = Lead::where('created_by', \Auth::user()->creatorId())->whereMonth('date', $request->start_month)->whereYear('date', $year)->get();

            } else {
                $leadFilter = Lead::where('created_by', \Auth::user()->creatorId())->whereMonth('date', $month)->whereYear('date', $year)->get();
            }

            $data[] = count($leadFilter);
            $labels[] = date('M Y', $currentdate);
            $currentdate = strtotime('+1 month', $currentdate);

            if (!empty($request->start_month)) {
                $cdate = '01-' . $request->start_month . '-' . $year;
                $mstart = strtotime($cdate);
                $labelss[] = date('M Y', $mstart);

                return response()->json(['data' => $data, 'name' => $labelss]);
            }
        }

        if (empty($request->start_month) && !empty($request->all())) {
            return response()->json(['data' => $data, 'name' => $labels]);
        }
        $filter['startDateRange'] = date('M-Y', $start);
        $filter['endDateRange'] = date('M-Y', $end);

        $monthList = $month = $this->yearMonth();

        //staff report

        if ($request->type == "staff_repport") {
            $form_date = date('Y-m-d H:i:s', strtotime($request->From_Date));
            $to_date = date('Y-m-d H:i:s', strtotime($request->To_Date));

            if (!empty($request->From_Date) && !empty($request->To_Date)) {

                $lead_user = User::where('created_by', \Auth::user()->id)->get();
                $leaduserName = [];
                $leadusereData = [];
                foreach ($lead_user as $lead_user_data) {
                    $lead_user = Lead::where('created_by', \Auth::user()->id)->where('user_id', $lead_user_data->id)->whereBetween('created_at', [$form_date, $to_date])->count();
                    $leaduserName[] = $lead_user_data->name;
                    $leadusereData[] = $lead_user;
                }
                return response()->json(['data' => $leadusereData, 'name' => $leaduserName]);
            }
        } else {
            $lead_user = User::where('created_by', \Auth::user()->id)->get();
            $leaduserName = [];
            $leadusereData = [];
            foreach ($lead_user as $lead_user_data) {
                $lead_user = Lead::where('created_by', \Auth::user()->id)->where('user_id', $lead_user_data->id)->count();
                $leaduserName[] = $lead_user_data->name;
                $leadusereData[] = $lead_user;
            }
        }

        $lead_pipeline = Pipeline::where('created_by', \Auth::user()->id)->get();

        $leadpipelineName = [];
        $leadpipelineeData = [];
        foreach ($lead_pipeline as $lead_pipeline_data) {
            $lead_pipeline = lead::where('created_by', \Auth::user()->id)->where('pipeline_id', $lead_pipeline_data->id)->count();
            $leadpipelineName[] = $lead_pipeline_data->name;
            $leadpipelineeData[] = $lead_pipeline;
        }

        return view('report.lead', compact('devicearray', 'leadsourceName', 'leadsourceeData', 'labels', 'data', 'filter', 'monthList', 'leads', 'leaduserName', 'leadusereData', 'user', 'leadpipelineName', 'leadpipelineeData'));
    }

    public function dealreport(Request $request)
    {
        $user = \Auth::user();
        $deals = Deal::orderBy('id');
        $deals->where('created_by', \Auth::user()->creatorId());

        $user_week_deal = Deal::orderBy('created_at')->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()])->get()->groupBy(function ($item) {
            return $item->created_at->format('Y-m-d');
        });

        $carbaoDay = Carbon::now()->startOfWeek();
        $weeks = [];
        for ($i = 0; $i < 7; $i++) {
            $weeks[$carbaoDay->startOfWeek()->addDay($i)->format('Y-m-d')] = 0;
        }
        foreach ($user_week_deal as $name => $deals) {
            $weeks[$name] = $deals->count();
        }

        $devicearray = [];
        $devicearray['label'] = [];
        $devicearray['data'] = [];
        foreach ($weeks as $name => $deals) {
            $devicearray['label'][] = Carbon::parse($name)->format('l');
            $devicearray['data'][] = $deals;
        }
        $deals = Deal::where('created_by', '=', \Auth::user()->creatorId())->get();

        $deals_source = Source::where('created_by', \Auth::user()->id)->get();

        $dealsourceName = [];
        $dealsourceeData = [];
        foreach ($deals_source as $deals_source_data) {
            $deals_source = Deal::where('created_by', \Auth::user()->id)->where('sources', $deals_source_data->id)->count();
            $dealsourceName[] = $deals_source_data->name;
            $dealsourceeData[] = $deals_source;
        }
        if ($request->type == "deal_staff_repport") {
            $from_date = date('Y-m-d H:i:s', strtotime($request->From_Date));
            $to_date = date('Y-m-d H:i:s', strtotime($request->To_Date));

            if (!empty($request->From_Date) && !empty($request->To_Date)) {
                $user_deal = $this->deals();
                $dealUserData = [];
                $dealUserName = [];
                foreach ($user_deal as $user_deal_data) {

                    $user_deals = UserDeal::where('user_id', $user_deal_data->id)->whereBetween('created_at', [$from_date, $to_date])->count();
                    $dealUserName[] = $user_deal_data->name;
                    $dealUserData[] = $user_deals;
                }
                return response()->json(['data' => $dealUserData, 'name' => $dealUserName]);
            }
        } else {
            $user_deal = $this->deals();
            $dealUserData = [];
            $dealUserName = [];
            foreach ($user_deal as $user_deal_data) {
                $user_deals = UserDeal::where('user_id', $user_deal_data->id)->count();

                $dealUserName[] = $user_deal_data->name;
                $dealUserData[] = $user_deals;
            }
        }

        $deal_pipeline = Pipeline::where('created_by', \Auth::user()->id)->get();

        $dealpipelineName = [];
        $dealpipelineeData = [];
        foreach ($deal_pipeline as $deal_pipeline_data) {
            $deal_pipeline = Deal::where('created_by', \Auth::user()->id)->where('pipeline_id', $deal_pipeline_data->id)->count();
            $dealpipelineName[] = $deal_pipeline_data->name;
            $dealpipelineeData[] = $deal_pipeline;
        }

        if ($request->type == "client_repport") {

            $from_date1 = date('Y-m-d H:i:s', strtotime($request->from_date));
            $to_date1 = date('Y-m-d H:i:s', strtotime($request->to_date));
            if (!empty($request->from_date) && !empty($request->to_date)) {
                $client_deal = $this->deals();
                $dealClientData = [];
                $dealClientName = [];
                foreach ($client_deal as $client_deal_data) {

                    $deals_client = ClientDeal::where('client_id', $client_deal_data->id)->whereBetween('created_at', [$from_date1, $to_date1])->count();
                    $dealClientName[] = $client_deal_data->name;
                    $dealClientData[] = $deals_client;
                }
                return response()->json(['data' => $dealClientData, 'name' => $dealClientName]);
            }
        } else {
            $client_deal = $this->deals();
            $dealClientName = [];
            $dealClientData = [];
            foreach ($client_deal as $client_deal_data) {
                $deals_client = ClientDeal::where('client_id', $client_deal_data->id)->count();
                $dealClientName[] = $client_deal_data->name;
                $dealClientData[] = $deals_client;
            }
        }
        $labels = [];
        $data = [];

        if (!empty($request->start_month) && !empty($request->end_month)) {
            $start = strtotime($request->start_month);
            $end = strtotime($request->end_month);
        } else {
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
        }

        $deals = Deal::orderBy('id');
        $deals->where('created_at', '>=', date('Y-m-01', $start))->where('created_at', '<=', date('Y-m-t', $end));
        $deals->where('created_by', \Auth::user()->creatorId());
        $deals = $deals->get();

        $currentdate = $start;
        while ($currentdate <= $end) {
            $month = date('m', $currentdate);

            $year = date('Y');

            if (!empty($request->start_month)) {
                $dealFilter = Deal::where('created_by', \Auth::user()->creatorId())->whereMonth('created_at', $request->start_month)->whereYear('created_at', $year)->get();
            } else {
                $dealFilter = Deal::where('created_by', \Auth::user()->creatorId())->whereMonth('created_at', $month)->whereYear('created_at', $year)->get();
            }

            $data[] = count($dealFilter);
            $labels[] = date('M Y', $currentdate);
            $currentdate = strtotime('+1 month', $currentdate);

            if (!empty($request->start_month)) {
                $cdate = '01-' . $request->start_month . '-' . $year;
                $mstart = strtotime($cdate);
                $labelss[] = date('M Y', $mstart);

                return response()->json(['data' => $data, 'name' => $labelss]);
            }
        }
        if (empty($request->start_month) && !empty($request->all())) {
            return response()->json(['data' => $data, 'name' => $labels]);
        }
        $filter['startDateRange'] = date('M-Y', $start);
        $filter['endDateRange'] = date('M-Y', $end);

        $monthList = $month = $this->yearMonth();
        return view('report.deal', compact('devicearray', 'dealsourceName', 'dealsourceeData', 'dealUserData', 'dealUserName', 'dealpipelineName', 'dealpipelineeData', 'data', 'labels', 'dealClientName', 'dealClientData', 'monthList'));
    }

    private static $dealData = null;

    public function deals()
    {
        if (self::$dealData == null) {
            $deal = User::where('created_by', \Auth::user()->creatorId())->get();
            self::$dealData = $deal;
        }

        return self::$dealData;
    }

    public function warehouseReport()
    {
        if (\Auth::user()->canManagePos()) {
            $warehouse = warehouse::where('created_by', \Auth::user()->id)->get();
            $totalWarehouse = warehouse::where('created_by', \Auth::user()->id)->count();
            $totalProduct = WarehouseProduct::where('created_by', '=', \Auth::user()->creatorId())->count();
            $warehousename = [];
            $warehouseProductData = [];
            foreach ($warehouse as $warehouse_data) {
                $warehouseGet = WarehouseProduct::where('created_by', \Auth::user()->id)->where('warehouse_id', $warehouse_data->id)->count();
                $warehousename[] = $warehouse_data->name;
                $warehouseProductData[] = $warehouseGet;
            }

            return view('report.warehouse', compact('warehouse', 'totalWarehouse', 'totalProduct', 'warehouseProductData', 'warehousename'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function purchaseDailyReport(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $warehouse = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $warehouse->prepend('All Warehouse', 0);
            $vendor = Vender::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $vendor->prepend('All Vendor', 0);
            $query = Purchase::where('created_by', '=', \Auth::user()->creatorId());
            if (!empty($request->warehouse)) {
                $query->where('warehouse_id', '=', $request->warehouse);
            }
            if (!empty($request->vendor)) {
                $query->where('vender_id', '=', $request->vendor);
            }

            $arrDuration = [];
            $data = [];
            if (!empty($request->start_date) && !empty($request->end_date)) {
                $first_date = $request->start_date;
                $end_date = $request->end_date;
            } else {
                $first_date = date('Y-m-d', strtotime('today - 30 days'));
                $end_date = date('Y-m-d', strtotime('today - 1 days'));
            }
            $query->whereBetween('purchase_date', [$first_date, $end_date]);
            $purchases = $query->get()->groupBy(
                function ($val) {
                    return Carbon::parse($val->purchase_date)->format('Y-m-d');
                });
            $total = [];
            if (!empty($purchases) && count($purchases) > 0) {
                foreach ($purchases as $day => $onepurchase) {
                    $totals = 0;
                    foreach ($onepurchase as $purchase) {
                        $totals += $purchase->getTotal();
                    }
                    $total[$day] = $totals;
                }
            }

            $previous_days = strtotime("-1 month +1 days");
            for ($i = 0; $i < 30; $i++) {
                $previous_days = strtotime(date('Y-m-d', $previous_days) . " +1 day");
                $arrDuration[] = date('d-M', $previous_days);
                $date = date('Y-m-d', $previous_days);
                $data[] = isset($total[$date]) ? $total[$date] : 0;
            }

            $filter['startDate'] = $first_date;
            $filter['endDate'] = $end_date;
            $warehouses = warehouse::where('id', '=', $request->warehouse)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['warehouse'] = !empty($warehouses) ? $warehouses->name : '';
            $vendors = Vender::where('id', '=', $request->vendor)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['vendor'] = !empty($vendors) ? $vendors->name : '';

            return view('report.daily_purchase', compact('warehouse', 'vendor', 'arrDuration', 'data', 'filter'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function purchaseMonthlyReport(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $monthList = $this->yearMonth();
            $yearList = $this->yearList();
            $warehouse = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $warehouse->prepend('All Warehouse', 0);
            $vendor = Vender::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $vendor->prepend('All Vendor', 0);
            $query = Purchase::where('created_by', '=', \Auth::user()->creatorId());
            if (!empty($request->warehouse)) {
                $query->where('warehouse_id', '=', $request->warehouse);
            }
            if (!empty($request->vendor)) {
                $query->where('vender_id', '=', $request->vendor);
            }
            $arrDuration = [];
            $data = [];
            if (!empty($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $query->whereYear('purchase_date', $year);
            $purchases = $query->get()->groupBy(
                function ($val) {
                    return Carbon::parse($val->purchase_date)->format('m');
                });
            $total = [];
            if (!empty($purchases) && count($purchases) > 0) {
                foreach ($purchases as $month => $onepurchase) {
                    $totals = 0;
                    foreach ($onepurchase as $purchase) {
                        $totals += $purchase->getTotal();
                    }
                    $total[$month] = $totals;
                }
            }
            for ($i = 0; $i < 12; $i++) {
                $arrDuration[] = date("my", strtotime(date('Y-m-01') . " -$i months"));
                $month = date("m", strtotime(date('Y-m-01') . " -$i months"));
                $data[] = isset($total[$month]) ? $total[$month] : 0;
            }

            $filter['startMonth'] = 'Jan-' . $year;
            $filter['endMonth'] = 'Dec-' . $year;
            $warehouses = warehouse::where('id', '=', $request->warehouse)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['warehouse'] = !empty($warehouses) ? $warehouses->name : '';
            $vendors = Vender::where('id', '=', $request->vendor)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['vendor'] = !empty($vendors) ? $vendors->name : '';

            return view('report.monthly_purchase', compact('monthList', 'yearList', 'warehouse', 'vendor', 'arrDuration', 'data', 'filter'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function posDailyReport(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $warehouse = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $warehouse->prepend('All Warehouse', 0);

            $customer = Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $customer->prepend('All Customer', 0);
            $query = Pos::where('created_by', '=', \Auth::user()->creatorId());
            if (!empty($request->warehouse)) {
                $query->where('warehouse_id', '=', $request->warehouse);
            }
            if (!empty($request->customer)) {
                $query->where('customer_id', '=', $request->customer);
            }

            $arrDuration = [];
            $data = [];
            if (!empty($request->start_date) && !empty($request->end_date)) {
                $first_date = $request->start_date;
                $end_date = $request->end_date;
            } else {
                $first_date = date('Y-m-d', strtotime('today - 30 days'));
                $end_date = date('Y-m-d', strtotime('today - 1 days'));

            }
            $query->whereBetween('pos_date', [$first_date, $end_date]);
            $poses = $query->get()->groupBy(
                function ($val) {
                    return Carbon::parse($val->pos_date)->format('Y-m-d');
                });
            $total = [];
            if (!empty($poses) && count($poses) > 0) {
                foreach ($poses as $day => $onepos) {
                    $totals = 0;
                    foreach ($onepos as $pos) {

                        $totals += $pos->getTotal();
                    }
                    $total[$day] = $totals;
                }
            }
            $previous_days = strtotime("-1 month +1 days");
            for ($i = 0; $i < 30; $i++) {
                $previous_days = strtotime(date('Y-m-d', $previous_days) . " +1 day");
                $arrDuration[] = date('d-M', $previous_days);
                $date = date('Y-m-d', $previous_days);
                $data[] = isset($total[$date]) ? $total[$date] : 0;
            }

            $filter['startDate'] = $first_date;
            $filter['endDate'] = $end_date;
            $warehouses = warehouse::where('id', '=', $request->warehouse)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['warehouse'] = !empty($warehouses) ? $warehouses->name : '';
            $customers = Customer::where('id', '=', $request->customer)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['customer'] = !empty($customers) ? $customers->name : '';

            return view('report.daily_pos', compact('warehouse', 'customer', 'arrDuration', 'data', 'filter'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function posMonthlyReport(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $monthList = $this->yearMonth();
            $yearList = $this->yearList();

            $warehouse = warehouse::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $warehouse->prepend('All Warehouse', 0);
            $customer = Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
            $customer->prepend('All Customer', 0);
            $query = Pos::where('created_by', '=', \Auth::user()->creatorId());
            if (!empty($request->warehouse)) {
                $query->where('warehouse_id', '=', $request->warehouse);
            }
            if (!empty($request->customer)) {
                $query->where('customer_id', '=', $request->customer);
            }
            $arrDuration = [];
            $data = [];
            if (!empty($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $query->whereYear('pos_date', $year);
            $poses = $query->get()->groupBy(
                function ($val) {
                    return Carbon::parse($val->pos_date)->format('m');
                });
            $total = [];
            if (!empty($poses) && count($poses) > 0) {
                foreach ($poses as $month => $onepos) {
                    $totals = 0;
                    foreach ($onepos as $pos) {
                        $totals += $pos->getTotal();
                    }
                    $total[$month] = $totals;
                }
            }
            for ($i = 0; $i < 12; $i++) {
                $arrDuration[] = date("my", strtotime(date('Y-m-01') . " -$i months"));
                $month = date("m", strtotime(date('Y-m-01') . " -$i months"));
                $data[] = isset($total[$month]) ? $total[$month] : 0;
            }

            $filter['startMonth'] = 'Jan-' . $year;
            $filter['endMonth'] = 'Dec-' . $year;
            $warehouses = warehouse::where('id', '=', $request->warehouse)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['warehouse'] = !empty($warehouses) ? $warehouses->name : '';
            $customers = Customer::where('id', '=', $request->customer)->where('created_by', \Auth::user()->creatorId())->first();
            $filter['customer'] = !empty($customers) ? $customers->name : '';

            return view('report.monthly_pos', compact('monthList', 'yearList', 'warehouse', 'customer', 'arrDuration', 'data', 'filter'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Phase 4: End-of-day (EOD) report — full format with company header, payment methods, tickets, employees, sections, taxes, items. Print + Excel.
     */
    public function posEodReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $date = $request->input('date', date('Y-m-d'));
        $creatorId = \Auth::user()->creatorId();
        $settings = Utility::settings();
        $companyName = $settings['company_name'] ?? config('app.name');
        $vatNumber = $settings['vat_number'] ?? '';
        $companyAddress = trim(implode(', ', array_filter([
            $settings['company_address'] ?? '',
            $settings['company_city'] ?? '',
            $settings['company_state'] ?? '',
            $settings['company_country'] ?? '',
            $settings['company_zipcode'] ?? '',
        ])));

        // All POS for the day (by pos_date and company)
        $poses = Pos::where('created_by', $creatorId)
            ->whereDate('pos_date', $date)
            ->with(['items', 'shift.user', 'posPayments'])
            ->orderBy('pos_id')
            ->get();

        $totalSales = $poses->sum(fn ($p) => $p->getTotal());
        $receiptsCount = $poses->count();
        $ticketFrom = $poses->min('pos_id');
        $ticketTo = $poses->max('pos_id');
        $salespersonName = $poses->first() && $poses->first()->shift && $poses->first()->shift->user
            ? $poses->first()->shift->user->name
            : (\Auth::user()->name ?? __('All'));

        // Sales by payment method (from PosPayment for these POS)
        $posIds = $poses->pluck('id');
        $paymentMethodRows = [];
        if ($posIds->isNotEmpty()) {
            $payments = \App\Models\PosPayment::whereIn('pos_id', $posIds)->get();
            $byMethod = $payments->groupBy(function ($p) {
                $m = $p->payment_method ?? 'cash';
                return $m === 'cash' ? __('Cash') : ($m === 'card' ? __('Card') : ($m === 'credit' ? __('Credit') : ucfirst($m)));
            });
            foreach ($byMethod as $methodName => $items) {
                $paymentMethodRows[] = [
                    'name' => $methodName,
                    'amount' => $items->sum('amount'),
                    'count' => $items->count(),
                ];
            }
        }
        // Cash received from pay-balance (invoice payments linked to shifts for this day)
        $shiftIdsForDay = \App\Models\PosShift::where('created_by', $creatorId)->whereDate('opened_at', $date)->pluck('id');
        $cashFromBalance = $shiftIdsForDay->isNotEmpty()
            ? (float) \App\Models\InvoicePayment::whereIn('pos_shift_id', $shiftIdsForDay)->where('payment_method', 0)->sum('amount')
            : 0;
        if ($cashFromBalance > 0) {
            $cashRowKey = null;
            foreach ($paymentMethodRows as $i => $r) {
                if ($r['name'] === __('Cash')) {
                    $cashRowKey = $i;
                    break;
                }
            }
            if ($cashRowKey !== null) {
                $paymentMethodRows[$cashRowKey]['amount'] += $cashFromBalance;
            } else {
                array_unshift($paymentMethodRows, ['name' => __('Cash'), 'amount' => $cashFromBalance, 'count' => 0]);
            }
        }
        if (empty($paymentMethodRows)) {
            $paymentMethodRows[] = ['name' => 'POS', 'amount' => $totalSales, 'count' => $receiptsCount];
        }

        // Statement by payment method (each ticket)
        $statementRows = [];
        foreach ($poses as $pos) {
            $employeeName = $pos->shift && $pos->shift->user ? $pos->shift->user->name : '-';
            $employeeId = $pos->shift && $pos->shift->user ? $pos->shift->user->id : '';
            $statementRows[] = [
                'ticket' => $pos->pos_id,
                'count' => $pos->items->sum('quantity'),
                'time' => $pos->created_at ? $pos->created_at->format('H:i') : '-',
                'employee' => $employeeId ? $employeeId . ' ' . $employeeName : $employeeName,
                'amount' => $pos->getTotal(),
            ];
        }

        // Sales per employee (by shift user)
        $salesPerEmployee = [];
        foreach ($poses as $pos) {
            $key = $pos->shift && $pos->shift->user
                ? ($pos->shift->user->id . '|' . $pos->shift->user->name)
                : '0|' . __('Without shift');
            if (!isset($salesPerEmployee[$key])) {
                $salesPerEmployee[$key] = ['name' => explode('|', $key)[1], 'amount' => 0, 'count' => 0];
            }
            $salesPerEmployee[$key]['amount'] += $pos->getTotal();
            $salesPerEmployee[$key]['count']++;
        }

        // Sales by section (category)
        $categorySummary = [];
        $itemsSold = [];
        $taxSummary = [];
        $getTaxData = Utility::getTaxData();
        foreach ($poses as $pos) {
            foreach ($pos->items as $item) {
                $product = \App\Models\ProductService::find($item->product_id);
                $price = $item->price ?? 0;
                $qty = $item->quantity;
                $amount = $price * $qty;
                $catName = __('Uncategorized');
                if ($product) {
                    $catId = $product->category_id ?? 0;
                    $catName = $catId ? (ProductServiceCategory::find($catId)?->name ?? __('Uncategorized')) : __('Uncategorized');
                }
                if (!isset($categorySummary[$catName])) {
                    $categorySummary[$catName] = ['qty' => 0, 'amount' => 0];
                }
                $categorySummary[$catName]['qty'] += $qty;
                $categorySummary[$catName]['amount'] += $amount;

                $pname = $product ? $product->name : __('Item');
                if (!isset($itemsSold[$pname])) {
                    $itemsSold[$pname] = ['qty' => 0, 'amount' => 0];
                }
                $itemsSold[$pname]['qty'] += $qty;
                $itemsSold[$pname]['amount'] += $amount;

                if (!empty($item->tax) && is_array($getTaxData)) {
                    foreach (explode(',', $item->tax) as $tid) {
                        $tid = trim($tid);
                        if ($tid === '') continue;
                        $rate = $getTaxData[$tid]['rate'] ?? 0;
                        $taxAmount = ($rate / 100) * ($price * $qty);
                        $base = $price * $qty;
                        $label = ($getTaxData[$tid]['name'] ?? 'Tax') . ' ' . ($rate ?: 0) . '%';
                        if (!isset($taxSummary[$label])) {
                            $taxSummary[$label] = ['base' => 0, 'quota' => 0, 'total' => 0, 'rate' => $rate];
                        }
                        $taxSummary[$label]['base'] += $base;
                        $taxSummary[$label]['quota'] += $taxAmount;
                        $taxSummary[$label]['total'] += $base + $taxAmount;
                    }
                }
            }
        }

        $customersCount = $poses->pluck('customer_id')->unique()->filter()->count();
        if ($customersCount === 0 && $receiptsCount > 0) {
            $customersCount = $receiptsCount;
        }
        $avgPerReceipt = $receiptsCount > 0 ? $totalSales / $receiptsCount : 0;
        $avgPerCustomer = $customersCount > 0 ? $totalSales / $customersCount : 0;

        $cashTotal = 0;
        $cardTotal = 0;
        $creditTotal = 0;
        foreach ($poses as $p) {
            foreach ($p->posPayments ?? [] as $pm) {
                $m = strtolower($pm->payment_method ?? 'cash');
                if ($m === 'cash') {
                    $cashTotal += $pm->amount;
                } elseif ($m === 'card' || in_array($m, ['mpesa', 'bank_transfer', 'visa', 'cheque'])) {
                    // card-like methods are still count as card here
                    $cardTotal += $pm->amount;
                } elseif ($m === 'credit') {
                    // credit sales are counted in totalSales but not in cash
                    $creditTotal += $pm->amount;
                } else {
                    // fallback treat as cash
                    $cashTotal += $pm->amount;
                }
            }
        }

        // calculate accounts receivable for credit sales and credit payments received
        $accountsReceivableRows = [];
        $accountsReceivableTotal = 0;
        if ($posIds->isNotEmpty()) {
            $creditInvoices = \App\Models\Invoice::whereIn('pos_id', $posIds)
                ->whereIn('status', [2, 3]) // unpaid or partially paid
                ->get();
            foreach ($creditInvoices as $inv) {
                $paid = $inv->getTotal() - $inv->getDue();
                $outstanding = $inv->getDue();
                $accountsReceivableRows[] = [
                    'invoice_id' => $inv->invoice_id,
                    'total' => $inv->getTotal(),
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                ];
                $accountsReceivableTotal += $outstanding;
            }
        }

        $receivedCreditPayments = [];
        if (!empty($shiftIdsForDay) && $shiftIdsForDay->isNotEmpty()) {
            $invoicePayments = \App\Models\InvoicePayment::whereIn('pos_shift_id', $shiftIdsForDay)
                ->whereDate('date', $date)
                ->with(['invoice'])
                ->get();
            foreach ($invoicePayments as $ip) {
                // payment_method: 0=cash, 1=card, 2=cheque, 3=bank_transfer, etc.
                $methodLabel = match((int)($ip->payment_method ?? 0)) {
                    0 => __('Cash'),
                    1 => __('Card'),
                    2 => __('Cheque'),
                    3 => __('Bank Transfer'),
                    default => __('Other'),
                };
                $receivedCreditPayments[] = [
                    'invoice_id' => $ip->invoice?->invoice_id ?? '',
                    'amount' => $ip->amount,
                    'payment_method' => $methodLabel,
                ];
            }
        }

        $openShiftsForDate = \App\Models\PosShift::where('created_by', $creatorId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->whereDate('opened_at', $date)
            ->with(['branch', 'user'])
            ->get();
        $isDayClosed = \App\Models\PosDayClosed::where('closed_date', $date)->where('created_by', $creatorId)->exists();

        $reportData = [
            'date' => $date,
            'companyName' => $companyName,
            'vatNumber' => $vatNumber,
            'companyAddress' => $companyAddress,
            'totalSales' => $totalSales,
            'ticketFrom' => $ticketFrom,
            'ticketTo' => $ticketTo,
            'receiptsCount' => $receiptsCount,
            'salespersonName' => $salespersonName,
            'paymentMethodRows' => $paymentMethodRows,
            'cashFromBalance' => $cashFromBalance ?? 0,
            'cashTotal' => $cashTotal,
            'creditTotal' => $creditTotal,
            'statementRows' => $statementRows,
            'salesPerEmployee' => array_values($salesPerEmployee),
            'customersCount' => $customersCount,
            'avgPerReceipt' => $avgPerReceipt,
            'avgPerCustomer' => $avgPerCustomer,
            'categorySummary' => $categorySummary,
            'taxSummary' => $taxSummary,
            'itemsSold' => $itemsSold,
            'accountsReceivableRows' => $accountsReceivableRows,
            'accountsReceivableTotal' => $accountsReceivableTotal,
            'receivedCreditPayments' => $receivedCreditPayments,
            'openShiftsForDate' => $openShiftsForDate,
            'isDayClosed' => $isDayClosed,
            'canCloseDay' => \Auth::user()->creatorId() === \Auth::user()->id,
        ];

        return view('report.pos_eod', $reportData);
    }

    /**
     * EOD report Excel export.
     */
    public function posEodReportExport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $date = $request->input('date', date('Y-m-d'));
        $creatorId = \Auth::user()->creatorId();
        $settings = Utility::settings();
        $companyName = $settings['company_name'] ?? config('app.name');
        $vatNumber = $settings['vat_number'] ?? '';
        $companyAddress = trim(implode(', ', array_filter([
            $settings['company_address'] ?? '',
            $settings['company_city'] ?? '',
            $settings['company_state'] ?? '',
            $settings['company_country'] ?? '',
        ])));

        $poses = Pos::where('created_by', $creatorId)
            ->whereDate('pos_date', $date)
            ->with(['items', 'shift.user', 'posPayments'])
            ->orderBy('pos_id')
            ->get();

        $totalSales = $poses->sum(fn ($p) => $p->getTotal());
        $receiptsCount = $poses->count();
        $ticketFrom = $poses->min('pos_id');
        $ticketTo = $poses->max('pos_id');

        // compute credit/cash/card totals for export in case needed
        $creditTotal = 0;
        foreach ($poses as $p) {
            foreach ($p->posPayments ?? [] as $pm) {
                $m = strtolower($pm->payment_method ?? 'cash');
                if ($m === 'credit') {
                    $creditTotal += $pm->amount;
                }
            }
        }

        // shifts opened on this date (for invoice payments)
        $shiftIdsForDay = \App\Models\PosShift::where('created_by', $creatorId)
            ->whereDate('opened_at', $date)
            ->pluck('id');

        $posIds = $poses->pluck('id');
        $paymentMethodRows = [];
        if ($posIds->isNotEmpty()) {
            $payments = \App\Models\PosPayment::whereIn('pos_id', $posIds)->get();
            $byMethod = $payments->groupBy(function ($p) {
                $m = $p->payment_method ?? 'cash';
                return $m === 'cash' ? __('Cash') : ($m === 'card' ? __('Card') : ($m === 'credit' ? __('Credit') : ucfirst($m)));
            });
            foreach ($byMethod as $methodName => $items) {
                $paymentMethodRows[] = ['name' => $methodName, 'amount' => $items->sum('amount'), 'count' => $items->count()];
            }
        }
        if (empty($paymentMethodRows)) {
            $paymentMethodRows[] = ['name' => 'POS', 'amount' => $totalSales, 'count' => $receiptsCount];
        }

        // accounts receivable rows
        $accountsReceivableRows = [];
        $accountsReceivableTotal = 0;
        if ($posIds->isNotEmpty()) {
            $creditInvoices = \App\Models\Invoice::whereIn('pos_id', $posIds)
                ->whereIn('status', [2, 3])
                ->get();
            foreach ($creditInvoices as $inv) {
                $paid = $inv->getTotal() - $inv->getDue();
                $outstanding = $inv->getDue();
                $accountsReceivableRows[] = [
                    'invoice_id' => $inv->invoice_id,
                    'total' => $inv->getTotal(),
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                ];
                $accountsReceivableTotal += $outstanding;
            }
        }

        $receivedCreditPayments = [];
        if ($shiftIdsForDay->isNotEmpty()) {
            $invoicePayments = \App\Models\InvoicePayment::whereIn('pos_shift_id', $shiftIdsForDay)
                ->whereDate('date', $date)
                ->with(['invoice'])
                ->get();
            foreach ($invoicePayments as $ip) {
                // payment_method: 0=cash, 1=card, 2=cheque, 3=bank_transfer, etc.
                $methodLabel = match((int)($ip->payment_method ?? 0)) {
                    0 => __('Cash'),
                    1 => __('Card'),
                    2 => __('Cheque'),
                    3 => __('Bank Transfer'),
                    default => __('Other'),
                };
                $receivedCreditPayments[] = [
                    'invoice_id' => $ip->invoice?->invoice_id ?? '',
                    'amount' => $ip->amount,
                    'payment_method' => $methodLabel,
                ];
            }
        }

        $statementRows = [];
        foreach ($poses as $pos) {
            $employeeName = $pos->shift && $pos->shift->user ? $pos->shift->user->name : '-';
            $statementRows[] = [
                'ticket' => $pos->pos_id,
                'count' => $pos->items->sum('quantity'),
                'time' => $pos->created_at ? $pos->created_at->format('H:i') : '-',
                'employee' => $employeeName,
                'amount' => $pos->getTotal(),
            ];
        }

        $salesPerEmployee = [];
        foreach ($poses as $pos) {
            $key = $pos->shift && $pos->shift->user ? ($pos->shift->user->id . '|' . $pos->shift->user->name) : '0|' . __('Without shift');
            if (!isset($salesPerEmployee[$key])) {
                $salesPerEmployee[$key] = ['name' => explode('|', $key)[1], 'amount' => 0, 'count' => 0];
            }
            $salesPerEmployee[$key]['amount'] += $pos->getTotal();
            $salesPerEmployee[$key]['count']++;
        }

        $categorySummary = [];
        $itemsSold = [];
        $taxSummary = [];
        $getTaxData = Utility::getTaxData();
        foreach ($poses as $pos) {
            foreach ($pos->items as $item) {
                $product = \App\Models\ProductService::find($item->product_id);
                $price = $item->price ?? 0;
                $qty = $item->quantity;
                $amount = $price * $qty;
                $catName = $product && $product->category_id ? (ProductServiceCategory::find($product->category_id)?->name ?? __('Uncategorized')) : __('Uncategorized');
                if (!isset($categorySummary[$catName])) {
                    $categorySummary[$catName] = ['qty' => 0, 'amount' => 0];
                }
                $categorySummary[$catName]['qty'] += $qty;
                $categorySummary[$catName]['amount'] += $amount;
                $pname = $product ? $product->name : __('Item');
                if (!isset($itemsSold[$pname])) {
                    $itemsSold[$pname] = ['qty' => 0, 'amount' => 0];
                }
                $itemsSold[$pname]['qty'] += $qty;
                $itemsSold[$pname]['amount'] += $amount;
                if (!empty($item->tax) && is_array($getTaxData)) {
                    foreach (explode(',', $item->tax) as $tid) {
                        $tid = trim($tid);
                        if ($tid === '') continue;
                        $rate = $getTaxData[$tid]['rate'] ?? 0;
                        $taxAmount = ($rate / 100) * ($price * $qty);
                        $base = $price * $qty;
                        $label = $getTaxData[$tid]['name'] ?? ($rate . '%');
                        if (!isset($taxSummary[$label])) {
                            $taxSummary[$label] = ['base' => 0, 'quota' => 0, 'total' => 0];
                        }
                        $taxSummary[$label]['base'] += $base;
                        $taxSummary[$label]['quota'] += $taxAmount;
                        $taxSummary[$label]['total'] += $base + $taxAmount;
                    }
                }
            }
        }

        $filename = 'EOD_Report_' . $date . '.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PosEodExport(
                $companyName,
                $vatNumber,
                $companyAddress,
                $date,
                $totalSales,
                $receiptsCount,
                $ticketFrom,
                $ticketTo,
                $paymentMethodRows,
                $statementRows,
                $salesPerEmployee,
                $categorySummary,
                $taxSummary,
                $itemsSold,
                $poses,
                $creditTotal,
                $accountsReceivableRows,
                $accountsReceivableTotal,
                $receivedCreditPayments
            ),
            $filename
        );
    }

    /**
     * Close POS day: block new shifts for that date. Requires no open shifts.
     */
    public function posCloseDay(Request $request)
    {
        if (!\Auth::user()->canManagePos() || \Auth::user()->creatorId() !== \Auth::user()->id) {
            return redirect()->back()->with('error', __('Permission denied. Only administrator can close the day.'));
        }
        $date = $request->input('date', date('Y-m-d'));
        $creatorId = \Auth::user()->creatorId();
        $openShifts = \App\Models\PosShift::where('created_by', $creatorId)
            ->where('status', \App\Models\PosShift::STATUS_OPEN)
            ->whereDate('opened_at', $date)
            ->count();
        if ($openShifts > 0) {
            return redirect()->route('report.pos.eod', ['date' => $date])
                ->with('error', __('Shift(s) still open. Please close all shifts before closing the day.'));
        }
        \App\Models\PosDayClosed::firstOrCreate(
            ['closed_date' => $date, 'created_by' => $creatorId],
            ['closed_by' => \Auth::user()->id, 'closed_at' => now()]
        );
        return redirect()->route('report.pos.eod', ['date' => $date])
            ->with('success', __('Day closed. No new shifts can be opened for this date until an administrator reopens.'));
    }

    /**
     * Reopen POS day (admin only). Allows opening shifts for that date again.
     */
    public function posReopenDay(Request $request)
    {
        if (!\Auth::user()->canManagePos() || \Auth::user()->creatorId() !== \Auth::user()->id) {
            return redirect()->back()->with('error', __('Permission denied. Only administrator can reopen the day.'));
        }
        $date = $request->input('date', date('Y-m-d'));
        $creatorId = \Auth::user()->creatorId();
        \App\Models\PosDayClosed::where('closed_date', $date)->where('created_by', $creatorId)->delete();
        return redirect()->route('report.pos.eod', ['date' => $date])
            ->with('success', __('Day reopened. Shifts can be opened for this date.'));
    }

    /**
     * Day summary: all shifts (opened or closed) for a given date. Summary table per shift.
     */
    public function posDaySummary(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $date = $request->input('date', date('Y-m-d'));
        $creatorId = \Auth::user()->creatorId();
        $shifts = PosShift::where('created_by', $creatorId)
            ->where(function ($q) use ($date) {
                $q->whereDate('opened_at', $date)
                    ->orWhereDate('closed_at', $date);
            })
            ->with(['branch', 'user'])
            ->orderBy('opened_at')
            ->get();
        // For open shifts, totals are not in DB yet — compute from Pos/PosPayment
        foreach ($shifts as $s) {
            if ($s->status === 'open' || $s->sales_total === null) {
                $posIds = Pos::where('shift_id', $s->id)->pluck('id');
                $s->sales_count = $posIds->count();
                $s->sales_total = Pos::where('shift_id', $s->id)->get()->sum(fn ($p) => $p->getTotal());
                $s->cash_total = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
                    $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
                })->sum('amount');
                $s->card_total = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
                $s->credit_total = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', PosPayment::METHOD_CREDIT)->sum('amount');
            }
        }
        return view('report.pos_day_summary', compact('date', 'shifts'));
    }

    /**
     * Phase 5: Low stock report – products below threshold.
     */
    public function posLowStockReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $globalThreshold = (float) (\App\Models\Utility::getValByName('pos_low_stock_threshold') ?: 10);
        if ($request->filled('threshold')) {
            $globalThreshold = (float) $request->threshold;
        }
        $products = ProductService::where('created_by', \Auth::user()->creatorId())
            ->where('type', 'product')
            ->where(function ($q) use ($globalThreshold) {
                $q->where(function ($q2) use ($globalThreshold) {
                    $q2->whereNotNull('low_stock_threshold')->whereRaw('quantity < low_stock_threshold');
                })->orWhere(function ($q2) use ($globalThreshold) {
                    $q2->whereNull('low_stock_threshold')->whereRaw('quantity < ?', [$globalThreshold]);
                });
            })
            ->orderBy('quantity')
            ->get();
        return view('report.pos_low_stock', compact('products', 'globalThreshold'));
    }

    public function posVsPurchaseReport(Request $request)
    {
        if (\Auth::user()->canManagePos()) {
            $data['monthList'] = $month = $this->yearMonth();
            $data['yearList'] = $this->yearList();

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $data['currentYear'] = $year;

            // ------------------------------TOTAL POS-----------------------------------------------------------

            $posData = Pos::selectRaw('MONTH(pos_date) as month,YEAR(pos_date) as year,pos_id,id')
                ->where('created_by', \Auth::user()->creatorId());
            $posData->whereRAW('YEAR(pos_date) =?', [$year]);
            $posData = $posData->get();
            $posTotalArray = [];
            foreach ($posData as $pos) {
                $posTotalArray[$pos->month] = $pos->getTotal();
            }

            // ------------------------------ TOTAL PAYMENT-----------------------------------------------------------
            $purchaseData = Purchase::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,purchase_id,id')
                ->where('created_by', \Auth::user()->creatorId())
                ->where('status', '!=', 0);
            $purchaseData->whereRAW('YEAR(send_date) =?', [$year]);
            $purchaseData = $purchaseData->get();
            $purchaseTotalArray = [];
            foreach ($purchaseData as $purchase) {
                $purchaseTotalArray[$purchase->month] = $purchase->getTotal();
            }

//            -----------------------------

            for ($i = 1; $i <= 12; $i++) {
                $PosTotal[] = array_key_exists($i, $posTotalArray) ? $posTotalArray[$i] : 0;
                $PurchaseTotal[] = array_key_exists($i, $purchaseTotalArray) ? $purchaseTotalArray[$i] : 0;

            }
            $totalPos = array_map(
                function () {
                    return array_sum(func_get_args());
                }, $PosTotal
            );

            $totalPurchase = array_map(
                function () {
                    return array_sum(func_get_args());
                }, $PurchaseTotal
            );

            $profits = [];
            $keys = array_keys($totalPos + $totalPurchase);
            foreach ($keys as $v) {
                $profits[$v] = number_format((empty($totalPos[$v]) ? 0 : $totalPos[$v]) - (empty($totalPurchase[$v]) ? 0 : $totalPurchase[$v]), 2);
            }

            $data['posTotal'] = $PosTotal;
            $data['purchaseTotal'] = $PurchaseTotal;
            $data['profits'] = $profits;
            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.pos_vs_purchase', compact('filter'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Phase 5: Cross-branch POS report – sales by branch and consolidated.
     */
    public function posBranchReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $branches = Branch::where('created_by', \Auth::user()->creatorId())->where('is_active', true)->get()->pluck('name', 'id');
        $branches->prepend(__('All Branches'), 0);
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $branchId = (int) $request->get('branch_id', 0);

        $query = Pos::where('created_by', \Auth::user()->creatorId())
            ->with(['branch', 'posPayment'])
            ->whereBetween('pos_date', [$start, $end]);
        if ($branchId > 0) {
            $query->where('branch_id', $branchId);
        }
        $poses = $query->get();

        $byBranch = [];
        $totalSales = 0;
        $totalCount = 0;
        foreach ($poses as $pos) {
            $bid = $pos->branch_id ?: 0;
            $bname = $pos->branch ? $pos->branch->name : __('No branch');
            if (!isset($byBranch[$bid])) {
                $byBranch[$bid] = ['name' => $bname, 'count' => 0, 'total' => 0];
            }
            $amt = $pos->posPayment ? (float) $pos->posPayment->discount_amount : $pos->getTotal();
            $byBranch[$bid]['count']++;
            $byBranch[$bid]['total'] += $amt;
            $totalCount++;
            $totalSales += $amt;
        }

        $filter = ['start_date' => $start, 'end_date' => $end, 'branch_id' => $branchId];
        return view('report.pos_branch', compact('branches', 'byBranch', 'totalSales', 'totalCount', 'filter'));
    }

    /**
     * Filterable POS shift report (multi-shift).
     */
    public function posShiftReport(Request $request)
    {
        if (! \Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $service = app(PosShiftReportService::class);
        $creatorId = \Auth::user()->creatorId();
        $filter = $service->normalizeFilters($request->all());
        $filterOptions = $this->posShiftReportFilterOptions($creatorId);

        $shiftIds = $service->getShiftIdsForFilters($creatorId, $filter);
        $summary = $shiftIds->count() > 100
            ? $service->buildSummaryFast($shiftIds, $creatorId)
            : $service->buildSummaryFromMetrics(
                PosShift::whereIn('id', $shiftIds)->with(['branch', 'user'])->get(),
                $creatorId
            );

        $aggregates = $service->buildAggregates($shiftIds, $creatorId, $filter['product_id']);

        $shifts = $service->queryShifts($creatorId, $filter)->paginate(50)->withQueryString();
        $shiftRows = [];
        foreach ($shifts as $shift) {
            $metrics = $service->buildShiftMetrics($shift, $creatorId);
            $shiftRows[] = [
                'shift' => $shift,
                'metrics' => $metrics,
            ];
        }

        return view('report.pos_shift', compact(
            'filter',
            'filterOptions',
            'shifts',
            'shiftRows',
            'summary',
            'aggregates',
            'shiftIds'
        ));
    }

    public function posShiftReportExport(Request $request)
    {
        if (! \Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $service = app(PosShiftReportService::class);
        $creatorId = \Auth::user()->creatorId();
        $filter = $service->normalizeFilters($request->all());
        $shiftIds = $service->getShiftIdsForFilters($creatorId, $filter);
        $summary = $service->buildSummaryFast($shiftIds, $creatorId);
        $aggregates = $service->buildAggregates($shiftIds, $creatorId, $filter['product_id']);

        $shiftRows = [];
        foreach (PosShift::whereIn('id', $shiftIds)->with(['branch', 'user'])->orderByDesc('opened_at')->get() as $shift) {
            $m = $service->buildShiftMetrics($shift, $creatorId);
            $shiftRows[] = [
                'id' => $shift->id,
                'branch' => $shift->branch ? $shift->branch->name : '-',
                'cashier' => $shift->user ? $shift->user->name : '-',
                'opened' => $shift->opened_at ? $shift->opened_at->format('Y-m-d H:i') : '-',
                'closed' => $shift->closed_at ? $shift->closed_at->format('Y-m-d H:i') : '-',
                'status' => $shift->status,
                'opening' => (float) ($shift->opening_balance ?? 0),
                'sales' => $m['salesTotal'],
                'cash' => $m['cashTotal'],
                'card' => $m['cardTotal'],
                'credit' => $m['creditTotal'],
                'expected_cash' => $m['expectedCash'],
                'difference' => $shift->status === PosShift::STATUS_CLOSED ? (float) ($shift->difference ?? 0) : null,
            ];
        }

        $filename = 'POS_Shift_Report_' . $filter['start_date'] . '_' . $filter['end_date'] . '.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PosShiftReportExport($filter, $summary, $shiftRows, $aggregates),
            $filename
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function posShiftReportFilterOptions(int $creatorId): array
    {
        $branches = Branch::where('created_by', $creatorId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $branches->prepend(__('All Branches'), 0);

        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->pluck('name', 'id');
        $warehouses->prepend(__('All Warehouses'), 0);

        $products = ProductService::where('created_by', $creatorId)->where('type', 'product & service')->orderBy('name')->pluck('name', 'id');
        $products->prepend(__('All Products'), 0);

        $users = User::where('created_by', $creatorId)->orderBy('name')->pluck('name', 'id');
        $users->prepend(__('All'), 0);

        $service = app(PosShiftReportService::class);

        return [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'products' => $products,
            'users' => $users,
            'shift_statuses' => [
                '' => __('All'),
                PosShift::STATUS_OPEN => __('Open'),
                PosShift::STATUS_CLOSED => __('Closed'),
            ],
            'order_types' => [
                '' => __('All'),
                Pos::ORDER_TYPE_DINE_IN => __('pos.dine_in'),
                Pos::ORDER_TYPE_DELIVERY => __('pos.delivery'),
                Pos::ORDER_TYPE_TAKEAWAY => __('pos.takeaway'),
            ],
            'payment_methods' => $service->paymentMethodOptions(),
        ];
    }

    /**
     * Phase 7: Restaurant reports dashboard – single entry point with links to all reports.
     */
    public function restaurantReportsIndex()
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        return view('report.restaurant_index');
    }

    /**
     * Sales by categories report for POS operations (shift closing + night audit).
     * Supports shift-wise and end-of-day scopes with optional stock visibility.
     */
    public function posSalesByCategoriesReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $reportData = $this->buildPosSalesByCategoryData($request);

        return view('report.restaurant_sales_by_category', $reportData);
    }

    public function posSalesByCategoriesExport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $reportData = $this->buildPosSalesByCategoryData($request);
        $filter = $reportData['filter'];
        $stamp = $filter['scope'] === 'eod'
            ? ($filter['date'] ?? date('Y-m-d'))
            : (($filter['start_date'] ?? date('Y-m-d')) . '_' . ($filter['end_date'] ?? date('Y-m-d')));
        $filename = 'POS_Sales_By_Categories_' . $stamp . '.xlsx';

        return Excel::download(
            new PosSalesByCategoryExport(
                $reportData['filter'],
                $reportData['summary'],
                $reportData['rows']->toArray(),
                $reportData['taxSummary'],
                $reportData['itemsSummary']
            ),
            $filename
        );
    }

    /**
     * Build filtered Sales by Categories dataset for UI and export.
     *
     * @return array<string,mixed>
     */
    protected function buildPosSalesByCategoryData(Request $request): array
    {
        $creatorId = \Auth::user()->creatorId();
        $scope = $request->get('scope', 'shift') === 'eod' ? 'eod' : 'shift';
        $withStock = (int) $request->get('with_stock', 1) === 1;
        $branchId = (int) $request->get('branch_id', 0);
        $warehouseId = (int) $request->get('warehouse_id', 0);
        $shiftId = (int) $request->get('shift_id', 0);
        $date = $request->get('date', date('Y-m-d'));
        $start = $request->get('start_date', date('Y-m-d'));
        $end = $request->get('end_date', date('Y-m-d'));

        $basePosQuery = Pos::query()
            ->where('created_by', $creatorId)
            ->whereNull('cancelled_at')
            ->whereHas('posPayments');

        if ($branchId > 0) {
            $basePosQuery->where('branch_id', $branchId);
        }
        if ($warehouseId > 0) {
            $basePosQuery->where('warehouse_id', $warehouseId);
        }

        if ($scope === 'eod') {
            $basePosQuery->whereDate('pos_date', $date);
        } else {
            if ($shiftId > 0) {
                $basePosQuery->where('shift_id', $shiftId);
            } else {
                $basePosQuery->whereBetween('pos_date', [$start, $end]);
            }
        }

        $posIds = $basePosQuery->pluck('id');
        $categoryRows = [];
        if ($posIds->isNotEmpty()) {
            $categoryRows = \DB::table('pos_products as pp')
                ->join('product_services as ps', 'ps.id', '=', 'pp.product_id')
                ->leftJoin('product_service_categories as c', 'c.id', '=', 'ps.category_id')
                ->whereIn('pp.pos_id', $posIds->all())
                ->selectRaw("COALESCE(c.id, 0) as category_id")
                ->selectRaw("COALESCE(c.name, 'Uncategorized') as category_name")
                ->selectRaw('SUM(pp.quantity) as qty_sold')
                ->selectRaw('SUM((pp.price * pp.quantity) - COALESCE(pp.discount, 0)) as amount_sold')
                ->groupBy('category_id', 'category_name')
                ->orderBy('amount_sold', 'desc')
                ->get();
        }

        $grandAmount = (float) collect($categoryRows)->sum(fn ($r) => (float) $r->amount_sold);
        $grandQty = (float) collect($categoryRows)->sum(fn ($r) => (float) $r->qty_sold);
        $rows = collect($categoryRows)->map(function ($row) use ($grandAmount) {
            $amount = (float) $row->amount_sold;
            return [
                'category_id' => (int) $row->category_id,
                'category_name' => (string) $row->category_name,
                'qty_sold' => (float) $row->qty_sold,
                'amount_sold' => $amount,
                'sales_pct' => $grandAmount > 0 ? round(($amount / $grandAmount) * 100, 2) : 0,
                'stock_qty' => null,
                'min_alerts' => 0,
                'max_alerts' => 0,
            ];
        })->values();

        $stockAlerts = collect();
        $globalMin = (float) (Utility::getValByName('pos_low_stock_threshold') ?: 10);
        $globalMax = (float) (Utility::getValByName('max_stock_threshold_global') ?: 0);
        $taxSummary = [];
        $itemsSummary = [];

        if ($withStock && $rows->isNotEmpty()) {
            $categoryIds = $rows->pluck('category_id')->filter(fn ($id) => $id > 0)->all();
            $stockByCategoryQuery = \DB::table('warehouse_products as wp')
                ->join('product_services as ps', 'ps.id', '=', 'wp.product_id')
                ->where('ps.created_by', $creatorId)
                ->where('ps.type', 'product')
                ->where('ps.affect_stock', 1);
            if (!empty($categoryIds)) {
                $stockByCategoryQuery->whereIn('ps.category_id', $categoryIds);
            }
            if ($warehouseId > 0) {
                $stockByCategoryQuery->where('wp.warehouse_id', $warehouseId);
            }
            $stockByCategory = $stockByCategoryQuery
                ->selectRaw('COALESCE(ps.category_id, 0) as category_id')
                ->selectRaw('SUM(wp.quantity) as stock_qty')
                ->groupBy('category_id')
                ->pluck('stock_qty', 'category_id');

            $stockPerProductSub = \DB::table('warehouse_products')
                ->when($warehouseId > 0, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->selectRaw('product_id, SUM(quantity) as stock_qty')
                ->groupBy('product_id');

            $productStockRows = ProductService::query()
                ->leftJoinSub($stockPerProductSub, 'wps', function ($join) {
                    $join->on('wps.product_id', '=', 'product_services.id');
                })
                ->leftJoin('product_service_categories as c', 'c.id', '=', 'product_services.category_id')
                ->where('product_services.created_by', $creatorId)
                ->where('product_services.type', 'product')
                ->where('product_services.affect_stock', 1)
                ->when(!empty($categoryIds), fn ($q) => $q->whereIn('product_services.category_id', $categoryIds))
                ->select([
                    'product_services.id',
                    'product_services.name',
                    'product_services.sku',
                    'product_services.category_id',
                    'product_services.low_stock_threshold',
                    'product_services.max_stock_threshold',
                    \DB::raw('COALESCE(c.name, "Uncategorized") as category_name'),
                    \DB::raw('COALESCE(wps.stock_qty, 0) as stock_qty'),
                ])
                ->get();

            $alertCounters = [];
            foreach ($productStockRows as $p) {
                $cid = (int) ($p->category_id ?? 0);
                $stockQty = (float) ($p->stock_qty ?? 0);
                $minAt = $p->low_stock_threshold !== null ? (float) $p->low_stock_threshold : $globalMin;
                $maxAt = $p->max_stock_threshold !== null ? (float) $p->max_stock_threshold : $globalMax;

                if ($stockQty < $minAt) {
                    $alertCounters[$cid]['min'] = ($alertCounters[$cid]['min'] ?? 0) + 1;
                    $stockAlerts->push([
                        'alert_type' => 'min',
                        'category_name' => $p->category_name,
                        'product_name' => $p->name,
                        'sku' => $p->sku,
                        'stock_qty' => $stockQty,
                        'threshold' => $minAt,
                    ]);
                }
                if ($maxAt > 0 && $stockQty > $maxAt) {
                    $alertCounters[$cid]['max'] = ($alertCounters[$cid]['max'] ?? 0) + 1;
                    $stockAlerts->push([
                        'alert_type' => 'max',
                        'category_name' => $p->category_name,
                        'product_name' => $p->name,
                        'sku' => $p->sku,
                        'stock_qty' => $stockQty,
                        'threshold' => $maxAt,
                    ]);
                }
            }

            $rows = $rows->map(function ($row) use ($stockByCategory, $alertCounters) {
                $cid = (int) $row['category_id'];
                $row['stock_qty'] = (float) ($stockByCategory[$cid] ?? 0);
                $row['min_alerts'] = (int) ($alertCounters[$cid]['min'] ?? 0);
                $row['max_alerts'] = (int) ($alertCounters[$cid]['max'] ?? 0);
                return $row;
            })->values();
        }

        if ($posIds->isNotEmpty()) {
            $lineRows = \DB::table('pos_products as pp')
                ->leftJoin('product_services as ps', 'ps.id', '=', 'pp.product_id')
                ->whereIn('pp.pos_id', $posIds->all())
                ->select([
                    'pp.tax',
                    'pp.quantity',
                    'pp.price',
                    \DB::raw('COALESCE(pp.discount, 0) as discount'),
                    \DB::raw("COALESCE(ps.name, 'Unknown') as product_name"),
                ])
                ->get();

            $taxIds = [];
            foreach ($lineRows as $line) {
                foreach (array_filter(array_map('trim', explode(',', (string) ($line->tax ?? '')))) as $tid) {
                    $taxIds[] = (int) $tid;
                }
            }
            $taxRates = Tax::whereIn('id', array_unique($taxIds))->pluck('rate', 'id');

            $taxBuckets = [];
            $itemBuckets = [];
            foreach ($lineRows as $line) {
                $qty = (float) ($line->quantity ?? 0);
                $price = (float) ($line->price ?? 0);
                $discount = (float) ($line->discount ?? 0);
                $base = max(0, ($price * $qty) - $discount);
                $rate = 0.0;
                foreach (array_filter(array_map('trim', explode(',', (string) ($line->tax ?? '')))) as $tid) {
                    $rate += (float) ($taxRates[(int) $tid] ?? 0);
                }
                $quota = $base * $rate / 100;
                $gross = $base + $quota;

                $rateLabel = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                $taxKey = $rateLabel . '% IVA';
                if (!isset($taxBuckets[$taxKey])) {
                    $taxBuckets[$taxKey] = ['base' => 0.0, 'quota' => 0.0, 'total' => 0.0];
                }
                $taxBuckets[$taxKey]['base'] += $base;
                $taxBuckets[$taxKey]['quota'] += $quota;
                $taxBuckets[$taxKey]['total'] += $gross;

                $name = (string) ($line->product_name ?: 'Unknown');
                if (!isset($itemBuckets[$name])) {
                    $itemBuckets[$name] = ['qty' => 0.0, 'amount' => 0.0];
                }
                $itemBuckets[$name]['qty'] += $qty;
                $itemBuckets[$name]['amount'] += $gross;
            }

            uasort($itemBuckets, fn ($a, $b) => $b['qty'] <=> $a['qty']);
            $taxSummary = $taxBuckets;
            $itemsSummary = $itemBuckets;
        }

        $branches = Branch::where('created_by', $creatorId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $branches->prepend(__('All Branches'), 0);
        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->pluck('name', 'id');
        $warehouses->prepend(__('All Warehouses'), 0);
        $shiftOptions = PosShift::where('created_by', $creatorId)
            ->with(['branch', 'user'])
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->mapWithKeys(function ($shift) {
                $label = '#' . $shift->id
                    . ' - ' . ($shift->branch->name ?? __('Branch'))
                    . ' - ' . ($shift->user->name ?? __('User'))
                    . ' (' . ($shift->opened_at ? $shift->opened_at->format('Y-m-d H:i') : '-') . ')';
                return [$shift->id => $label];
            });
        $shiftOptions->prepend(__('All Shifts in Date Range'), 0);

        return [
            'rows' => $rows,
            'stockAlerts' => $stockAlerts,
            'filter' => [
                'scope' => $scope,
                'with_stock' => $withStock ? 1 : 0,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'shift_id' => $shiftId,
                'date' => $date,
                'start_date' => $start,
                'end_date' => $end,
            ],
            'filterOptions' => [
                'branches' => $branches,
                'warehouses' => $warehouses,
                'shifts' => $shiftOptions,
            ],
            'summary' => [
                'categories_count' => $rows->count(),
                'total_qty' => $grandQty,
                'total_sales' => $grandAmount,
                'total_stock' => (float) $rows->sum(fn ($r) => (float) ($r['stock_qty'] ?? 0)),
                'total_min_alerts' => (int) $rows->sum(fn ($r) => (int) ($r['min_alerts'] ?? 0)),
                'total_max_alerts' => (int) $rows->sum(fn ($r) => (int) ($r['max_alerts'] ?? 0)),
            ],
            'taxSummary' => $taxSummary,
            'itemsSummary' => $itemsSummary,
        ];
    }

    /**
     * Phase 7: Sales by product (paid POS only).
     */
    public function posSalesByProductReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $paidPosIds = Pos::where('created_by', $creatorId)
            ->whereBetween('pos_date', [$start, $end])
            ->whereHas('posPayments')
            ->pluck('id');
        $lines = PosProduct::whereIn('pos_id', $paidPosIds)
            ->with('product')
            ->get();
        $byProduct = [];
        foreach ($lines as $line) {
            $pid = $line->product_id ?: 0;
            $name = $line->product ? $line->product->name : __('Unknown');
            $revenue = (float) ($line->price * $line->quantity - $line->discount);
            if (!isset($byProduct[$pid])) {
                $byProduct[$pid] = ['name' => $name, 'quantity' => 0, 'revenue' => 0];
            }
            $byProduct[$pid]['quantity'] += $line->quantity;
            $byProduct[$pid]['revenue'] += $revenue;
        }
        usort($byProduct, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        return view('report.restaurant_sales_by_product', compact('byProduct', 'start', 'end'));
    }

    /**
     * Phase 7: Sales by waiter (shift user).
     */
    public function posSalesByWaiterReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $poses = Pos::where('created_by', $creatorId)
            ->whereBetween('pos_date', [$start, $end])
            ->with(['shift.user', 'posPayments'])
            ->whereHas('posPayments')
            ->get();
        $byWaiter = [];
        foreach ($poses as $pos) {
            $uid = $pos->shift_id ? ($pos->shift->user_id ?? 0) : 0;
            $name = $pos->shift && $pos->shift->user ? $pos->shift->user->name : __('No waiter');
            $total = (float) $pos->posPayments->sum('amount');
            if (!isset($byWaiter[$uid])) {
                $byWaiter[$uid] = ['name' => $name, 'count' => 0, 'total' => 0];
            }
            $byWaiter[$uid]['count']++;
            $byWaiter[$uid]['total'] += $total;
        }
        usort($byWaiter, fn($a, $b) => $b['total'] <=> $a['total']);
        return view('report.restaurant_sales_by_waiter', compact('byWaiter', 'start', 'end'));
    }

    /**
     * Phase 7: Cancelled orders (Pos with cancelled_at or cancelled lines).
     */
    public function posCancellationsReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $cancelled = Pos::where('created_by', $creatorId)
            ->whereBetween('pos_date', [$start, $end])
            ->whereNotNull('cancelled_at')
            ->with(['branch', 'table'])
            ->orderBy('cancelled_at', 'desc')
            ->get();
        return view('report.restaurant_cancellations', compact('cancelled', 'start', 'end'));
    }

    /**
     * Phase 7: Discounts applied (from PosProduct and PosPayment).
     */
    public function posDiscountsReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $poses = Pos::where('created_by', $creatorId)->whereBetween('pos_date', [$start, $end])->pluck('id');
        $lineDiscount = (float) PosProduct::whereIn('pos_id', $poses)->sum('discount');
        $paymentDiscount = (float) PosPayment::whereIn('pos_id', $poses)->sum('discount_amount');
        $totalDiscount = $lineDiscount + $paymentDiscount;
        $rows = Pos::where('created_by', $creatorId)
            ->whereBetween('pos_date', [$start, $end])
            ->with(['posPayments', 'items'])
            ->get()
            ->filter(fn($p) => $p->getTotalDiscount() > 0 || ($p->posPayments->sum('discount_amount') > 0));
        return view('report.restaurant_discounts', compact('rows', 'totalDiscount', 'start', 'end'));
    }

    /**
     * Phase 7: Best-selling items (by quantity).
     */
    public function posBestSellingReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $paidPosIds = Pos::where('created_by', $creatorId)->whereBetween('pos_date', [$start, $end])->whereHas('posPayments')->pluck('id');
        $lines = PosProduct::whereIn('pos_id', $paidPosIds)->with('product')->get();
        $byProduct = [];
        foreach ($lines as $line) {
            $pid = $line->product_id ?: 0;
            $name = $line->product ? $line->product->name : __('Unknown');
            if (!isset($byProduct[$pid])) {
                $byProduct[$pid] = ['name' => $name, 'quantity' => 0, 'revenue' => 0];
            }
            $byProduct[$pid]['quantity'] += $line->quantity;
            $byProduct[$pid]['revenue'] += (float) ($line->price * $line->quantity - $line->discount);
        }
        usort($byProduct, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        $byProduct = array_slice($byProduct, 0, 50);
        return view('report.restaurant_best_selling', compact('byProduct', 'start', 'end'));
    }

    /**
     * Phase 7: Gross profit (revenue - cost using product purchase_price).
     */
    public function posGrossProfitReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $paidPosIds = Pos::where('created_by', $creatorId)->whereBetween('pos_date', [$start, $end])->whereHas('posPayments')->pluck('id');
        $lines = PosProduct::whereIn('pos_id', $paidPosIds)->with('product')->get();
        $revenue = 0;
        $cost = 0;
        foreach ($lines as $line) {
            $revenue += (float) ($line->price * $line->quantity - $line->discount);
            $c = $line->product ? (float) ($line->product->purchase_price ?? 0) : 0;
            $cost += $c * $line->quantity;
        }
        $profit = $revenue - $cost;
        return view('report.restaurant_gross_profit', compact('revenue', 'cost', 'profit', 'start', 'end'));
    }

    /**
     * Phase 7: Cash discrepancies (closed shifts with difference != 0).
     */
    public function posCashDiscrepanciesReport(Request $request)
    {
        if (!\Auth::user()->canManagePos()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $creatorId = \Auth::user()->creatorId();
        $start = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $end = $request->get('end_date', date('Y-m-d'));
        $shifts = PosShift::where('created_by', $creatorId)
            ->where('status', PosShift::STATUS_CLOSED)
            ->whereBetween('closed_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->whereRaw('COALESCE(difference, 0) != 0')
            ->with(['branch', 'user'])
            ->orderBy('closed_at', 'desc')
            ->get();
        return view('report.restaurant_cash_discrepancies', compact('shifts', 'start', 'end'));
    }

    public function profitLoss(Request $request, $view = '', $collapseView = 'expand')
    {
        if (\Auth::user()->can('income vs expense report')) {
            if (!empty($request->start_date) && !empty($request->end_date)) {
                $start = $request->start_date;
                $end = $request->end_date;
            } else {
                $start = date('Y-01-01');
                $end = date('Y-m-d', strtotime('+1 day'));
            }
            $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->whereIn('name', ['Income', 'Expenses', 'Costs of Goods Sold'])->get();
            $subTypeArray = [];
            $totalAccounts = [];
            foreach ($types as $type) {

                $parentAccounts = ChartOfAccountParent::where('type', $type->id)->where('created_by', \Auth::user()->creatorId())->get();

                $totalParentAccountArray = [];
                if ($parentAccounts->isNotEmpty()) {
                    foreach ($parentAccounts as $parentAccount) {
                        $totalArray = [];
                        $parentAccountArray = [];
                        $parentAccountArrayTotal = [];

                        $parentAccs = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                        $parentAccs->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                        $parentAccs->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                        $parentAccs->where('chart_of_accounts.type', $type->id);
                        $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                        $parentAccs->where('transaction_lines.created_by', \Auth::user()->creatorId());
                        $parentAccs->where('transaction_lines.date', '>=', $start);
                        $parentAccs->where('transaction_lines.date', '<=', $end);
                        $parentAccs->groupBy('account_id');
                        $parentAccs = $parentAccs->get()->toArray();

                        $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                        $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                        $accounts->where('chart_of_accounts.type', $type->id);
                        $accounts->where('chart_of_accounts.parent', $parentAccount->id);
                        $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                        $accounts->where('transaction_lines.date', '>=', $start);
                        $accounts->where('transaction_lines.date', '<=', $end);
                        $accounts->groupBy('account_id');
                        $accounts = $accounts->get()->toArray();

                        if ($parentAccs == [] && $accounts != []) {

                            $parentAccs = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('0 as totalDebit'), \DB::raw('0 as totalCredit'));
                            $parentAccs->leftjoin('chart_of_account_parents', 'chart_of_accounts.id', 'chart_of_account_parents.account');
                            $parentAccs->where('chart_of_accounts.type', $type->id);
                            $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                            $parentAccs->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                            $parentAccs = $parentAccs->get()->toArray();
                        }
                        if ($parentAccs != [] && $accounts == []) {

                            $parentAccs = [];
                        }

                        $parenttotalBalance = 0;
                        $parentcreditTotal = 0;
                        $parenntdebitTotal = 0;
                        $parenttotalAmount = 0;

                        foreach ($parentAccs as $account) {
                            $Balance = $account['totalCredit'] - $account['totalDebit'];
                            $parenttotalBalance += $Balance;

                            $data = [
                                'account_id' => $account['id'],
                                'account_code' => $account['code'],
                                'account_name' => $account['name'],
                                'account' => 'parent',
                                'totalCredit' => 0,
                                'totalDebit' => 0,
                                'netAmount' => $Balance,
                            ];

                            $parentAccountArray[] = $data;
                            $parentcreditTotal += $data['totalCredit'];
                            $parenntdebitTotal += $data['totalDebit'];
                            $parenttotalAmount += $data['netAmount'];
                        }

                        foreach ($accounts as $account) {
                            $Balance = $account['totalCredit'] - $account['totalDebit'];
                            $parenttotalBalance += $Balance;

                            if ($Balance != 0) {
                                $data = [
                                    'account_id' => $account['id'],
                                    'account_code' => $account['code'],
                                    'account_name' => $account['name'],
                                    'account' => 'subAccount',
                                    'totalCredit' => 0,
                                    'totalDebit' => 0,
                                    'netAmount' => $Balance,
                                ];

                                $parentAccountArray[] = $data;
                                $parentcreditTotal += $data['totalCredit'];
                                $parenntdebitTotal += $data['totalDebit'];
                                $parenttotalAmount += $data['netAmount'];
                            }
                        }

                        if (!empty($parentAccountArray)) {
                            $dataTotal = [
                                'account_id' => $parentAccount->account,
                                'account_code' => '',
                                'account' => 'parentTotal',
                                'account_name' => 'Total ' . $parentAccount->name,
                                'totalCredit' => $parentcreditTotal,
                                'totalDebit' => $parenntdebitTotal,
                                'netAmount' => $parenttotalAmount,
                            ];

                            $parentAccountArrayTotal[] = $dataTotal;
                            $totalArray = array_merge($parentAccountArray, $parentAccountArrayTotal);
                            $totalParentAccountArray[] = $totalArray;
                        }

                    }

                }

                if ($totalParentAccountArray != []) {
                    $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $accounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.name', 'chart_of_account_parents.name');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('chart_of_account_parents.account');
                    $accounts->where('chart_of_accounts.parent', '=', 'chart_of_account_parents.id');
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();
                } else {
                    $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();
                }

                $totalBalance = 0;
                $creditTotal = 0;
                $debitTotal = 0;
                $totalAmount = 0;
                $accountArray = [];
                foreach ($accounts as $account) {
                    $Balance = $account['totalCredit'] - $account['totalDebit'];
                    $totalBalance += $Balance;
                    if ($Balance != 0) {
                        $data['account_id'] = $account['id'];
                        $data['account_code'] = $account['code'];
                        $data['account_name'] = $account['name'];
                        $data['account'] = '';
                        $data['totalCredit'] = 0;
                        $data['totalDebit'] = 0;
                        $data['netAmount'] = $Balance;
                        $accountArray[][] = $data;
                        $creditTotal += $data['totalCredit'];
                        $debitTotal += $data['totalDebit'];
                        $totalAmount += $data['netAmount'];
                    }
                }

                $totalAccountArray = [];

                if ($accountArray != []) {
                    $dataTotal['account_id'] = '';
                    $dataTotal['account_code'] = '';
                    $dataTotal['account'] = '';
                    $dataTotal['account_name'] = 'Total ' . $type->name;
                    $dataTotal['totalCredit'] = $creditTotal;
                    $dataTotal['totalDebit'] = $debitTotal;
                    $dataTotal['netAmount'] = $totalAmount;
                    $accountArray[][] = $dataTotal;
                    $totalAccountArray = array_merge($totalParentAccountArray, $accountArray);

                } elseif ($totalParentAccountArray != []) {

                    $dataTotal['account_id'] = '';
                    $dataTotal['account_code'] = '';
                    $dataTotal['account'] = '';
                    $dataTotal['account_name'] = 'Total ' . $type->name;
                    $dataTotal['totalCredit'] = $creditTotal;
                    $dataTotal['totalDebit'] = $debitTotal;
                    $netAmount = 0;
                    foreach ($totalParentAccountArray as $innerArray) {
                        $lastElement = end($innerArray);

                        $netAmount += $lastElement['netAmount'];
                    }
                    $dataTotal['netAmount'] = $netAmount;
                    $accountArrayTotal[][] = $dataTotal;
                    $totalAccountArray = array_merge($totalParentAccountArray, $accountArrayTotal);
                }
                if ($totalAccountArray != []) {
                    $subTypeData['Type'] = ($totalAccountArray != []) ? $type->name : '';
                    $subTypeData['account'] = $totalAccountArray;
                    $subTypeArray[] = ($subTypeData['account'] != []) ? $subTypeData : [];
                }
                $totalAccounts = $subTypeArray;
            }

            $filter['startDateRange'] = $start;
            $filter['endDateRange'] = $end;
            if ($request->view == 'horizontal' || $view == 'horizontal') {
                return view('report.profit_loss_horizontal', compact('filter', 'totalAccounts', 'collapseView'));
            } elseif ($view == '' || $view == 'vertical') {
                return view('report.profit_loss', compact('filter', 'totalAccounts', 'collapseView'));
            } else {
                return redirect()->back();
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    public function monthlyCashflow(Request $request)
    {
        if (\Auth::user()->can('loss & profit report')) {

            $data['monthList'] = $month = $this->yearMonth();
            $data['yearList'] = $this->yearList();

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $data['currentYear'] = $year;

            // -------------------------------REVENUE INCOME-------------------------------------------------

            // ------------------------------REVENUE INCOME-----------------------------------
            $incomes = Revenue::selectRaw('sum(revenues.amount) as amount,MONTH(date) as month,YEAR(date) as year,category_id')
                ->leftjoin('product_service_categories', 'revenues.category_id', '=', 'product_service_categories.id')->where('product_service_categories.type', '=', 1);
            $incomes->where('revenues.created_by', '=', \Auth::user()->creatorId());
            $incomes->whereRAW('YEAR(date) =?', [$year]);

            if (!empty($request->category)) {
                $incomes->where('category_id', '=', $request->category);
                $cat = ProductServiceCategory::find($request->category);
                $filter['category'] = !empty($cat) ? $cat->name : '';
            }

            if (!empty($request->customer)) {
                $incomes->where('customer_id', '=', $request->customer);
                $cust = Customer::find($request->customer);
                $filter['customer'] = !empty($cust) ? $cust->name : '';
            }
            $incomes->groupBy('month', 'year', 'category_id');
            $incomes = $incomes->get();

            $tmpArray = [];
            foreach ($incomes as $income) {
                $tmpArray[$income->category_id][$income->month] = $income->amount;
            }
            $array = [];
            foreach ($tmpArray as $cat_id => $record) {
                $tmp = [];
                $tmp['category'] = !empty(ProductServiceCategory::where('id', '=', $cat_id)->first()) ? ProductServiceCategory::where('id', '=', $cat_id)->first()->name : '';
                $tmp['data'] = [];
                for ($i = 1; $i <= 12; $i++) {
                    $tmp['data'][$i] = array_key_exists($i, $record) ? $record[$i] : 0;
                }
                $array[] = $tmp;
            }

            $incomesData = Revenue::selectRaw('sum(revenues.amount) as amount,MONTH(date) as month,YEAR(date) as year');
            $incomesData->where('revenues.created_by', '=', \Auth::user()->creatorId());
            $incomesData->whereRAW('YEAR(date) =?', [$year]);

            if (!empty($request->category)) {
                $incomesData->where('category_id', '=', $request->category);
            }
            if (!empty($request->customer)) {
                $incomesData->where('customer_id', '=', $request->customer);
            }
            $incomesData->groupBy('month', 'year');
            $incomesData = $incomesData->get();
            $incomeArr = [];
            foreach ($incomesData as $k => $incomeData) {
                $incomeArr[$incomeData->month] = $incomeData->amount;
            }
            for ($i = 1; $i <= 12; $i++) {
                $incomeTotal[] = array_key_exists($i, $incomeArr) ? $incomeArr[$i] : 0;
            }

            //---------------------------INVOICE INCOME-----------------------------------------------

            $invoices = Invoice::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,category_id,invoice_id,id')
                ->where('created_by', \Auth::user()->creatorId())
                ->where('status', '!=', 0);

            $invoices->whereRAW('YEAR(send_date) =?', [$year]);

            if (!empty($request->customer)) {
                $invoices->where('customer_id', '=', $request->customer);
            }

            if (!empty($request->category)) {
                $invoices->where('category_id', '=', $request->category);
            }

            $invoices = $invoices->get();
            $invoiceTmpArray = [];
            foreach ($invoices as $invoice) {
                $invoiceTmpArray[$invoice->category_id][$invoice->month][] = $invoice->getTotal();
            }

            $invoiceArray = [];
            foreach ($invoiceTmpArray as $cat_id => $record) {

                $invoice = [];
                $productCtegory = ProductServiceCategory::where('id', '=', $cat_id)->first();
                $invoice['category'] = !empty($productCtegory) ? $productCtegory->name : '';
                $invoice['data'] = [];
                for ($i = 1; $i <= 12; $i++) {

                    $invoice['data'][$i] = array_key_exists($i, $record) ? array_sum($record[$i]) : 0;
                }
                $invoiceArray[] = $invoice;
            }

            $invoiceTotalArray = [];
            foreach ($invoices as $invoice) {
                $invoiceTotalArray[$invoice->month][] = $invoice->getTotal();
            }
            for ($i = 1; $i <= 12; $i++) {
                $invoiceTotal[] = array_key_exists($i, $invoiceTotalArray) ? array_sum($invoiceTotalArray[$i]) : 0;
            }

            $chartIncomeArr = array_map(
                function () {
                    return array_sum(func_get_args());
                }, $incomeTotal, $invoiceTotal
            );

            $data['chartIncomeArr'] = $chartIncomeArr;
            $data['incomeArr'] = $array;
            $data['invoiceArray'] = $invoiceArray;

            //   -----------------------------------------PAYMENT EXPENSE ------------------------------------------------------------
            $expenses = Payment::selectRaw('sum(payments.amount) as amount,MONTH(date) as month,YEAR(date) as year,category_id')->leftjoin('product_service_categories', 'payments.category_id', '=', 'product_service_categories.id')->where('product_service_categories.type', '=', 2);
            $expenses->where('payments.created_by', '=', \Auth::user()->creatorId());
            $expenses->whereRAW('YEAR(date) =?', [$year]);

            if (!empty($request->category)) {
                $expenses->where('category_id', '=', $request->category);
                $cat = ProductServiceCategory::find($request->category);
                $filter['category'] = !empty($cat) ? $cat->name : '';
            }
            if (!empty($request->vender)) {
                $expenses->where('vender_id', '=', $request->vender);

                $vend = Vender::find($request->vender);
                $filter['vender'] = !empty($vend) ? $vend->name : '';
            }

            $expenses->groupBy('month', 'year', 'category_id');
            $expenses = $expenses->get();
            $tmpArray = [];
            foreach ($expenses as $expense) {
                $tmpArray[$expense->category_id][$expense->month] = $expense->amount;
            }
            $array = [];
            foreach ($tmpArray as $cat_id => $record) {
                $tmp = [];
                $tmp['category'] = !empty(ProductServiceCategory::where('id', '=', $cat_id)->first()) ? ProductServiceCategory::where('id', '=', $cat_id)->first()->name : '';
                $tmp['data'] = [];
                for ($i = 1; $i <= 12; $i++) {
                    $tmp['data'][$i] = array_key_exists($i, $record) ? $record[$i] : 0;
                }
                $array[] = $tmp;
            }
            $expensesData = Payment::selectRaw('sum(payments.amount) as amount,MONTH(date) as month,YEAR(date) as year');
            $expensesData->where('payments.created_by', '=', \Auth::user()->creatorId());
            $expensesData->whereRAW('YEAR(date) =?', [$year]);

            if (!empty($request->category)) {
                $expensesData->where('category_id', '=', $request->category);
            }
            if (!empty($request->vender)) {
                $expensesData->where('vender_id', '=', $request->vender);
            }
            $expensesData->groupBy('month', 'year');
            $expensesData = $expensesData->get();

            $expenseArr = [];
            foreach ($expensesData as $k => $expenseData) {
                $expenseArr[$expenseData->month] = $expenseData->amount;
            }
            for ($i = 1; $i <= 12; $i++) {
                $expenseTotal[] = array_key_exists($i, $expenseArr) ? $expenseArr[$i] : 0;
            }

            //     ------------------------------------BILL EXPENSE----------------------------------------------------

            $bills = Bill::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,category_id,bill_id,id')->where('created_by', \Auth::user()->creatorId())->where('status', '!=', 0);
            $bills->whereRAW('YEAR(send_date) =?', [$year]);

            if (!empty($request->vender)) {
                $bills->where('vender_id', '=', $request->vender);
            }

            if (!empty($request->category)) {
                $bills->where('category_id', '=', $request->category);
            }
            $bills = $bills->get();
            $billTmpArray = [];
            foreach ($bills as $bill) {
                $billTmpArray[$bill->category_id][$bill->month][] = $bill->getTotal();
            }

            $billArray = [];
            foreach ($billTmpArray as $cat_id => $record) {

                $bill = [];
                $productCategory = ProductServiceCategory::where('id', '=', $cat_id)->first();
                $bill['category'] = !empty($productCategory) ? $productCategory->name : '';
                $bill['data'] = [];
                for ($i = 1; $i <= 12; $i++) {

                    $bill['data'][$i] = array_key_exists($i, $record) ? array_sum($record[$i]) : 0;
                }
                $billArray[] = $bill;
            }

            $billTotalArray = [];
            foreach ($bills as $bill) {
                $billTotalArray[$bill->month][] = $bill->getTotal();
            }
            for ($i = 1; $i <= 12; $i++) {
                $billTotal[] = array_key_exists($i, $billTotalArray) ? array_sum($billTotalArray[$i]) : 0;
            }

            $chartExpenseArr = array_map(
                function () {
                    return array_sum(func_get_args());
                }, $expenseTotal, $billTotal
            );

            $netProfit = [];
            $keys = array_keys($chartIncomeArr + $chartExpenseArr);
            foreach ($keys as $v) {
                $netProfit[$v] = (empty($chartIncomeArr[$v]) ? 0 : $chartIncomeArr[$v]) - (empty($chartExpenseArr[$v]) ? 0 : $chartExpenseArr[$v]);
            }

            $data['chartExpenseArr'] = $chartExpenseArr;
            $data['expenseArr'] = $array;
            $data['billArray'] = $billArray;

            $data['netProfitArray'] = $netProfit;
            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.monthly_cashflow', compact('filter'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function quarterlyCashflow(Request $request)
    {

        if (\Auth::user()->can('loss & profit report')) {
            $data['month'] = [
                'Jan-Mar',
                'Apr-Jun',
                'Jul-Sep',
                'Oct-Dec',
                'Total',
            ];
            $data['monthList'] = $month = $this->yearMonth();
            $data['yearList'] = $this->yearList();

            if (isset($request->year)) {
                $year = $request->year;
            } else {
                $year = date('Y');
            }
            $data['currentYear'] = $year;

            // -------------------------------REVENUE INCOME-------------------------------------------------

            $incomes = Revenue::selectRaw('sum(revenues.amount) as amount,MONTH(date) as month,YEAR(date) as year,category_id');
            $incomes->where('created_by', '=', \Auth::user()->creatorId());
            $incomes->whereRAW('YEAR(date) =?', [$year]);
            $incomes->groupBy('month', 'year', 'category_id');
            $incomes = $incomes->get();
            $tmpIncomeArray = [];
            foreach ($incomes as $income) {
                $tmpIncomeArray[$income->category_id][$income->month] = $income->amount;
            }

            $incomeCatAmount_1 = $incomeCatAmount_2 = $incomeCatAmount_3 = $incomeCatAmount_4 = 0;
            $revenueIncomeArray = array();
            foreach ($tmpIncomeArray as $cat_id => $record) {

                $tmp = [];
                $tmp['category'] = !empty(ProductServiceCategory::where('id', '=', $cat_id)->first()) ? ProductServiceCategory::where('id', '=', $cat_id)->first()->name : '';
                $sumData = [];
                for ($i = 1; $i <= 12; $i++) {
                    $sumData[] = array_key_exists($i, $record) ? $record[$i] : 0;
                }

                $month_1 = array_slice($sumData, 0, 3);
                $month_2 = array_slice($sumData, 3, 3);
                $month_3 = array_slice($sumData, 6, 3);
                $month_4 = array_slice($sumData, 9, 3);

                $incomeData[__('Jan-Mar')] = $sum_1 = array_sum($month_1);
                $incomeData[__('Apr-Jun')] = $sum_2 = array_sum($month_2);
                $incomeData[__('Jul-Sep')] = $sum_3 = array_sum($month_3);
                $incomeData[__('Oct-Dec')] = $sum_4 = array_sum($month_4);
                $incomeData[__('Total')] = array_sum(
                    array(
                        $sum_1,
                        $sum_2,
                        $sum_3,
                        $sum_4,
                    )
                );

                $incomeCatAmount_1 += $sum_1;
                $incomeCatAmount_2 += $sum_2;
                $incomeCatAmount_3 += $sum_3;
                $incomeCatAmount_4 += $sum_4;

                $data['month'] = array_keys($incomeData);
                $tmp['amount'] = array_values($incomeData);

                $revenueIncomeArray[] = $tmp;

            }

            $data['incomeCatAmount'] = $incomeCatAmount = [
                $incomeCatAmount_1,
                $incomeCatAmount_2,
                $incomeCatAmount_3,
                $incomeCatAmount_4,
                array_sum(
                    array(
                        $incomeCatAmount_1,
                        $incomeCatAmount_2,
                        $incomeCatAmount_3,
                        $incomeCatAmount_4,
                    )
                ),
            ];

            $data['revenueIncomeArray'] = $revenueIncomeArray;

            //-----------------------INVOICE INCOME---------------------------------------------

            $invoices = Invoice::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,category_id,invoice_id,id')->where('created_by', \Auth::user()->creatorId())->where('status', '!=', 0);
            $invoices->whereRAW('YEAR(send_date) =?', [$year]);
            if (!empty($request->customer)) {
                $invoices->where('customer_id', '=', $request->customer);
            }
            $invoices = $invoices->get();

            $invoiceTmpArray = [];
            foreach ($invoices as $invoice) {
                $invoiceTmpArray[$invoice->category_id][$invoice->month][] = $invoice->getDue();
            }

            $invoiceCatAmount_1 = $invoiceCatAmount_2 = $invoiceCatAmount_3 = $invoiceCatAmount_4 = 0;

            $invoiceIncomeArray = array();
            foreach ($invoiceTmpArray as $cat_id => $record) {

                $invoiceTmp = [];
                $invoiceTmp['category'] = !empty(ProductServiceCategory::where('id', '=', $cat_id)->first()) ? ProductServiceCategory::where('id', '=', $cat_id)->first()->name : '';
                $invoiceSumData = [];
                for ($i = 1; $i <= 12; $i++) {
                    $invoiceSumData[] = array_key_exists($i, $record) ? array_sum($record[$i]) : 0;

                }

                $month_1 = array_slice($invoiceSumData, 0, 3);
                $month_2 = array_slice($invoiceSumData, 3, 3);
                $month_3 = array_slice($invoiceSumData, 6, 3);
                $month_4 = array_slice($invoiceSumData, 9, 3);
                $invoiceIncomeData[__('Jan-Mar')] = $sum_1 = array_sum($month_1);
                $invoiceIncomeData[__('Apr-Jun')] = $sum_2 = array_sum($month_2);
                $invoiceIncomeData[__('Jul-Sep')] = $sum_3 = array_sum($month_3);
                $invoiceIncomeData[__('Oct-Dec')] = $sum_4 = array_sum($month_4);
                $invoiceIncomeData[__('Total')] = array_sum(
                    array(
                        $sum_1,
                        $sum_2,
                        $sum_3,
                        $sum_4,
                    )
                );
                $invoiceCatAmount_1 += $sum_1;
                $invoiceCatAmount_2 += $sum_2;
                $invoiceCatAmount_3 += $sum_3;
                $invoiceCatAmount_4 += $sum_4;

                $invoiceTmp['amount'] = array_values($invoiceIncomeData);

                $invoiceIncomeArray[] = $invoiceTmp;

            }

            $data['invoiceIncomeCatAmount'] = $invoiceIncomeCatAmount = [
                $invoiceCatAmount_1,
                $invoiceCatAmount_2,
                $invoiceCatAmount_3,
                $invoiceCatAmount_4,
                array_sum(
                    array(
                        $invoiceCatAmount_1,
                        $invoiceCatAmount_2,
                        $invoiceCatAmount_3,
                        $invoiceCatAmount_4,
                    )
                ),
            ];

            $data['invoiceIncomeArray'] = $invoiceIncomeArray;

            $data['totalIncome'] = $totalIncome = array_map(
                function () {
                    return array_sum(func_get_args());
                }, $invoiceIncomeCatAmount, $incomeCatAmount
            );

            //---------------------------------PAYMENT EXPENSE-----------------------------------

            $expenses = Payment::selectRaw('sum(payments.amount) as amount,MONTH(date) as month,YEAR(date) as year,category_id');
            $expenses->where('created_by', '=', \Auth::user()->creatorId());
            $expenses->whereRAW('YEAR(date) =?', [$year]);
            $expenses->groupBy('month', 'year', 'category_id');
            $expenses = $expenses->get();

            $tmpExpenseArray = [];
            foreach ($expenses as $expense) {
                $tmpExpenseArray[$expense->category_id][$expense->month] = $expense->amount;
            }

            $expenseArray = [];
            $expenseCatAmount_1 = $expenseCatAmount_2 = $expenseCatAmount_3 = $expenseCatAmount_4 = 0;
            foreach ($tmpExpenseArray as $cat_id => $record) {
                $tmp = [];
                $tmp['category'] = !empty(ProductServiceCategory::where('id', '=', $cat_id)->first()) ? ProductServiceCategory::where('id', '=', $cat_id)->first()->name : '';
                $expenseSumData = [];
                for ($i = 1; $i <= 12; $i++) {
                    $expenseSumData[] = array_key_exists($i, $record) ? $record[$i] : 0;

                }

                $month_1 = array_slice($expenseSumData, 0, 3);
                $month_2 = array_slice($expenseSumData, 3, 3);
                $month_3 = array_slice($expenseSumData, 6, 3);
                $month_4 = array_slice($expenseSumData, 9, 3);

                $expenseData[__('Jan-Mar')] = $sum_1 = array_sum($month_1);
                $expenseData[__('Apr-Jun')] = $sum_2 = array_sum($month_2);
                $expenseData[__('Jul-Sep')] = $sum_3 = array_sum($month_3);
                $expenseData[__('Oct-Dec')] = $sum_4 = array_sum($month_4);
                $expenseData[__('Total')] = array_sum(
                    array(
                        $sum_1,
                        $sum_2,
                        $sum_3,
                        $sum_4,
                    )
                );

                $expenseCatAmount_1 += $sum_1;
                $expenseCatAmount_2 += $sum_2;
                $expenseCatAmount_3 += $sum_3;
                $expenseCatAmount_4 += $sum_4;

                $data['month'] = array_keys($expenseData);
                $tmp['amount'] = array_values($expenseData);

                $expenseArray[] = $tmp;

            }

            $data['expenseCatAmount'] = $expenseCatAmount = [
                $expenseCatAmount_1,
                $expenseCatAmount_2,
                $expenseCatAmount_3,
                $expenseCatAmount_4,
                array_sum(
                    array(
                        $expenseCatAmount_1,
                        $expenseCatAmount_2,
                        $expenseCatAmount_3,
                        $expenseCatAmount_4,
                    )
                ),
            ];
            $data['expenseArray'] = $expenseArray;

            //    ----------------------------EXPENSE BILL-----------------------------------------------------------------------

            $bills = Bill::selectRaw('MONTH(send_date) as month,YEAR(send_date) as year,category_id,bill_id,id')->where('created_by', \Auth::user()->creatorId())->where('status', '!=', 0);
            $bills->whereRAW('YEAR(send_date) =?', [$year]);
            if (!empty($request->customer)) {
                $bills->where('vender_id', '=', $request->vender);
            }
            $bills = $bills->get();
            $billTmpArray = [];
            foreach ($bills as $bill) {
                $billTmpArray[$bill->category_id][$bill->month][] = $bill->getTotal();
            }

            $billExpenseArray = [];
            $billExpenseCatAmount_1 = $billExpenseCatAmount_2 = $billExpenseCatAmount_3 = $billExpenseCatAmount_4 = 0;
            foreach ($billTmpArray as $cat_id => $record) {
                $billTmp = [];
                $billTmp['category'] = !empty(ProductServiceCategory::where('id', '=', $cat_id)->first()) ? ProductServiceCategory::where('id', '=', $cat_id)->first()->name : '';
                $billExpensSumData = [];
                for ($i = 1; $i <= 12; $i++) {
                    $billExpensSumData[] = array_key_exists($i, $record) ? array_sum($record[$i]) : 0;
                }

                $month_1 = array_slice($billExpensSumData, 0, 3);
                $month_2 = array_slice($billExpensSumData, 3, 3);
                $month_3 = array_slice($billExpensSumData, 6, 3);
                $month_4 = array_slice($billExpensSumData, 9, 3);

                $billExpenseData[__('Jan-Mar')] = $sum_1 = array_sum($month_1);
                $billExpenseData[__('Apr-Jun')] = $sum_2 = array_sum($month_2);
                $billExpenseData[__('Jul-Sep')] = $sum_3 = array_sum($month_3);
                $billExpenseData[__('Oct-Dec')] = $sum_4 = array_sum($month_4);
                $billExpenseData[__('Total')] = array_sum(
                    array(
                        $sum_1,
                        $sum_2,
                        $sum_3,
                        $sum_4,
                    )
                );

                $billExpenseCatAmount_1 += $sum_1;
                $billExpenseCatAmount_2 += $sum_2;
                $billExpenseCatAmount_3 += $sum_3;
                $billExpenseCatAmount_4 += $sum_4;

                $data['month'] = array_keys($billExpenseData);
                $billTmp['amount'] = array_values($billExpenseData);

                $billExpenseArray[] = $billTmp;

            }

            $data['billExpenseCatAmount'] = $billExpenseCatAmount = [
                $billExpenseCatAmount_1,
                $billExpenseCatAmount_2,
                $billExpenseCatAmount_3,
                $billExpenseCatAmount_4,
                array_sum(
                    array(
                        $billExpenseCatAmount_1,
                        $billExpenseCatAmount_2,
                        $billExpenseCatAmount_3,
                        $billExpenseCatAmount_4,
                    )
                ),
            ];

            $data['billExpenseArray'] = $billExpenseArray;

            $data['totalExpense'] = $totalExpense = array_map(
                function () {
                    return array_sum(func_get_args());
                }, $billExpenseCatAmount, $expenseCatAmount
            );

            foreach ($totalIncome as $k => $income) {
                $netProfit[] = $income - $totalExpense[$k];
            }
            $data['netProfitArray'] = $netProfit;

            $filter['startDateRange'] = 'Jan-' . $year;
            $filter['endDateRange'] = 'Dec-' . $year;

            return view('report.quarterly_cashflow', compact('filter'), $data);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function trialBalanceExport(Request $request)
    {
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-m-01');
            $end = date('Y-m-t', strtotime('+1 day'));
        }

        $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->get();
        $chartAccounts = [];

        $totalAccounts = [];
        $totalAccounts = [];

        foreach ($types as $type) {

            // $type->id = 4;
            $parentAccounts = ChartOfAccountParent::where('type', $type->id)->where('created_by', \Auth::user()->creatorId())->get();

            $totalParentAccountArray = [];
            if ($parentAccounts->isNotEmpty()) {
                foreach ($parentAccounts as $parentAccount) {
                    $totalArray = [];
                    $parentAccountArray = [];
                    $parentAccountArrayTotal = [];

                    $parentAccs = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $parentAccs->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $parentAccs->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $parentAccs->where('chart_of_accounts.type', $type->id);
                    $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                    $parentAccs->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $parentAccs->where('transaction_lines.date', '>=', $start);
                    $parentAccs->where('transaction_lines.date', '<=', $end);
                    $parentAccs->groupBy('account_id');
                    $parentAccs = $parentAccs->get()->toArray();

                    $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('chart_of_accounts.parent', $parentAccount->id);
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();

                    if ($parentAccs == [] && $accounts != []) {

                        $parentAccs = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('0 as totalDebit'), \DB::raw('0 as totalCredit'));
                        $parentAccs->leftjoin('chart_of_account_parents', 'chart_of_accounts.id', 'chart_of_account_parents.account');
                        $parentAccs->where('chart_of_accounts.type', $type->id);
                        $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                        $parentAccs->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                        $parentAccs = $parentAccs->get()->toArray();
                    } elseif ($parentAccs != [] && $accounts == []) {
                        $parentAccs = [];

                    }

                    $parenttotalBalance = 0;
                    $parentcreditTotal = 0;
                    $parenntdebitTotal = 0;
                    $parenttotalAmount = 0;

                    foreach ($parentAccs as $account) {
                        $Balance = $account['totalCredit'] - $account['totalDebit'];
                        $parenttotalBalance += $Balance;

                        $data = [
                            'account_id' => $account['id'],
                            'account_code' => $account['code'],
                            'account_name' => $account['name'],
                            'account' => 'parent',
                            'totalCredit' => $account['totalCredit'],
                            'totalDebit' => $account['totalDebit'],
                        ];

                        $parentAccountArray[] = $data;
                        $parentcreditTotal += $data['totalCredit'];
                        $parenntdebitTotal += $data['totalDebit'];
                    }

                    foreach ($accounts as $account) {
                        $Balance = $account['totalCredit'] - $account['totalDebit'];

                        if ($Balance != 0) {
                            $data = [
                                'account_id' => $account['id'],
                                'account_code' => $account['code'],
                                'account_name' => $account['name'],
                                'account' => 'subAccount',
                                'totalCredit' => $account['totalCredit'],
                                'totalDebit' => $account['totalDebit'],
                            ];

                            $parentAccountArray[] = $data;
                            $parentcreditTotal += $data['totalCredit'];
                            $parenntdebitTotal += $data['totalDebit'];
                        }
                    }

                    if (!empty($parentAccountArray)) {

                        $dataTotal = [
                            'account_id' => $parentAccount->account,
                            'account_code' => '',
                            'account' => 'parentTotal',
                            'account_name' => 'Total ' . $parentAccount->name,
                            'totalCredit' => $parentcreditTotal,
                            'totalDebit' => $parenntdebitTotal,
                        ];

                        $parentAccountArrayTotal[] = $dataTotal;
                    }

                    if ($parentAccountArray != []) {
                        $totalArray = array_merge($parentAccountArray, $parentAccountArrayTotal);
                        $totalParentAccountArray[] = $totalArray;
                    }
                }
            }

            if ($totalParentAccountArray != []) {
                $accounts = TransactionLines::select('chart_of_accounts.id as account_id', 'chart_of_accounts.code as account_code', 'chart_of_accounts.name as account_name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                $accounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.name', 'chart_of_account_parents.name');
                $accounts->where('chart_of_accounts.type', $type->id);
                $accounts->where('chart_of_account_parents.account');
                $accounts->where('chart_of_accounts.parent', '=', 'chart_of_account_parents.id');
                $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                $accounts->where('transaction_lines.date', '>=', $start);
                $accounts->where('transaction_lines.date', '<=', $end);
                $accounts->groupBy('account_id');
                $accounts = $accounts->get()->toArray();
            } else {
                $accounts = TransactionLines::select('chart_of_accounts.id as account_id', 'chart_of_accounts.code as account_code', 'chart_of_accounts.name as account_name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                $accounts->where('chart_of_accounts.type', $type->id);
                $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                $accounts->where('transaction_lines.date', '>=', $start);
                $accounts->where('transaction_lines.date', '<=', $end);
                $accounts->groupBy('account_id');
                $accounts = $accounts->get()->toArray();
            }

            $name = $type->name;
            if (isset($totalAccount[$name])) {
                $totalAccount[$name]["totalCredit"] += $accounts["totalCredit"];
                $totalAccount[$name]["totalDebit"] += $accounts["totalDebit"];
            } else {
                $totalAccount[$name] = $accounts;
            }
            if ($totalParentAccountArray != []) {
                $totalAccount[$name] = array_merge_recursive($totalAccount[$name], $totalParentAccountArray[0]);
            }
        }

        foreach ($totalAccount as $category => $entries) {
            foreach ($entries as $entry) {
                $name = $entry['account_name'];
                if (!isset($totalAccounts[$category][$name])) {
                    $totalAccounts[$category][$name] = [
                        'account_id' => $entry['account_id'],
                        'account_code' => $entry['account_code'],
                        'account_name' => $name,
                        'account' => isset($entry['account']) ? $entry['account'] : '',
                        'totalDebit' => 0,
                        'totalCredit' => 0,
                    ];
                }
                if ($entry['totalDebit'] < 0) {
                    $totalAccounts[$category][$name]['totalDebit'] += 0;
                    $totalAccounts[$category][$name]['totalCredit'] += -$entry['totalDebit'];
                } else {
                    $totalAccounts[$category][$name]['totalDebit'] += $entry['totalDebit'];
                    $totalAccounts[$category][$name]['totalCredit'] += $entry['totalCredit'];
                }
            }
        }
        $companyName = User::where('id', \Auth::user()->creatorId())->first();
        $companyName = $companyName->name;

        $name = 'trial_balance_' . date('Y-m-d i:h:s');
        $data = Excel::download(new TrialBalancExport($totalAccounts, $start, $end, $companyName), $name . '.xlsx');
        ob_end_clean();

        return $data;
    }

    public function balanceSheetExport(Request $request)
    {
        $currentStart = !empty($request->start_date) ? Carbon::parse($request->start_date) : Carbon::now()->startOfYear();
        $currentEnd = !empty($request->end_date) ? Carbon::parse($request->end_date) : Carbon::now();
        if ($currentStart->gt($currentEnd)) {
            [$currentStart, $currentEnd] = [$currentEnd->copy(), $currentStart->copy()];
        }

        $previousStart = $currentStart->copy()->subYear();
        $previousEnd = $currentEnd->copy()->subYear();
        $creatorId = \Auth::user()->creatorId();

        $lineMappings = [
            ['section' => 'Assets', 'label' => 'Fixed Assets', 'code_label' => '32', 'prefixes' => ['32']],
            ['section' => 'Assets', 'label' => 'Intangible Assets', 'code_label' => '33', 'prefixes' => ['33']],
            ['section' => 'Assets', 'label' => 'Inventory', 'code_label' => '21', 'prefixes' => ['21']],
            ['section' => 'Assets', 'label' => 'Clients', 'code_label' => '411', 'prefixes' => ['411']],
            ['section' => 'Assets', 'label' => 'Cash / Bank', 'code_label' => '11,12', 'prefixes' => ['11', '12']],
            ['section' => 'Liabilities', 'label' => 'Tax Payable', 'code_label' => '4437', 'prefixes' => ['4437']],
            ['section' => 'Equity', 'label' => 'Equity', 'code_label' => '59', 'prefixes' => ['59']],
        ];

        $rows = [];
        foreach ($lineMappings as $mapping) {
            $rows[] = [
                'section' => $mapping['section'],
                'label' => $mapping['label'],
                'code_label' => $mapping['code_label'],
                'current_year' => $this->sumBalanceByCodePrefixes($mapping['prefixes'], $currentStart, $currentEnd, $creatorId),
                'previous_year' => $this->sumBalanceByCodePrefixes($mapping['prefixes'], $previousStart, $previousEnd, $creatorId),
            ];
        }

        $assetsCurrent = collect($rows)->where('section', 'Assets')->sum('current_year');
        $assetsPrevious = collect($rows)->where('section', 'Assets')->sum('previous_year');
        $liabilitiesCurrent = collect($rows)->where('section', 'Liabilities')->sum('current_year');
        $liabilitiesPrevious = collect($rows)->where('section', 'Liabilities')->sum('previous_year');
        $equityCurrent = collect($rows)->where('section', 'Equity')->sum('current_year');
        $equityPrevious = collect($rows)->where('section', 'Equity')->sum('previous_year');

        $totals = [
            'assets' => ['current' => $assetsCurrent, 'previous' => $assetsPrevious],
            'liabilities' => ['current' => $liabilitiesCurrent, 'previous' => $liabilitiesPrevious],
            'equity' => ['current' => $equityCurrent, 'previous' => $equityPrevious],
            'equity_liabilities' => [
                'current' => $equityCurrent + $liabilitiesCurrent,
                'previous' => $equityPrevious + $liabilitiesPrevious,
            ],
            'difference' => [
                'current' => $assetsCurrent - ($equityCurrent + $liabilitiesCurrent),
                'previous' => $assetsPrevious - ($equityPrevious + $liabilitiesPrevious),
            ],
        ];

        $settings = Utility::settings();
        $header = [
            'company_name' => $settings['company_name'] ?? (\Auth::user()->name ?? config('app.name')),
            'nuit' => $settings['vat_number'] ?? '',
            'currency' => $settings['site_currency'] ?? 'USD',
        ];

        $filter = [
            'startDateRange' => $currentStart->format('Y-m-d'),
            'endDateRange' => $currentEnd->format('Y-m-d'),
            'previousStartDateRange' => $previousStart->format('Y-m-d'),
            'previousEndDateRange' => $previousEnd->format('Y-m-d'),
            'currentYear' => (string) Carbon::now()->year,
            'previousYear' => (string) (Carbon::now()->year - 1),
        ];

        $name = 'balance_sheet_' . date('Y-m-d_H-i-s');
        $data = Excel::download(new BalanceSheetExport($rows, $totals, $header, $filter), $name . '.xlsx');
        ob_end_clean();

        return $data;
    }

    public function profitLossExport(Request $request)
    {

        if (\Auth::user()->can('income vs expense report')) {

            if (!empty($request->start_date) && !empty($request->end_date)) {
                $start = $request->start_date;
                $end = $request->end_date;
            } else {
                $start = date('Y-01-01');
                $end = date('Y-m-d', strtotime('+1 day'));
            }

            $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->whereIn('name', ['Income', 'Expenses', 'Costs of Goods Sold'])->get();
            $subTypeArray = [];
            $totalAccounts = [];
            foreach ($types as $type) {

                $parentAccounts = ChartOfAccountParent::where('type', $type->id)->where('created_by', \Auth::user()->creatorId())->get();

                $totalParentAccountArray = [];
                if ($parentAccounts->isNotEmpty()) {
                    foreach ($parentAccounts as $parentAccount) {
                        $totalArray = [];
                        $parentAccountArray = [];
                        $parentAccountArrayTotal = [];

                        $parentAccs = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                        $parentAccs->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                        $parentAccs->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                        $parentAccs->where('chart_of_accounts.type', $type->id);
                        $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                        $parentAccs->where('transaction_lines.created_by', \Auth::user()->creatorId());
                        $parentAccs->where('transaction_lines.date', '>=', $start);
                        $parentAccs->where('transaction_lines.date', '<=', $end);
                        $parentAccs->groupBy('account_id');
                        $parentAccs = $parentAccs->get()->toArray();

                        $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                        $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                        $accounts->where('chart_of_accounts.type', $type->id);
                        $accounts->where('chart_of_accounts.parent', $parentAccount->id);
                        $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                        $accounts->where('transaction_lines.date', '>=', $start);
                        $accounts->where('transaction_lines.date', '<=', $end);
                        $accounts->groupBy('account_id');
                        $accounts = $accounts->get()->toArray();

                        if ($parentAccs == [] && $accounts != []) {

                            $parentAccs = ChartOfAccount::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('0 as totalDebit'), \DB::raw('0 as totalCredit'));
                            $parentAccs->leftjoin('chart_of_account_parents', 'chart_of_accounts.id', 'chart_of_account_parents.account');
                            $parentAccs->where('chart_of_accounts.type', $type->id);
                            $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                            $parentAccs->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                            $parentAccs = $parentAccs->get()->toArray();
                        }
                        if ($parentAccs != [] && $accounts == []) {

                            $parentAccs = [];
                        }

                        $parenttotalBalance = 0;
                        $parentcreditTotal = 0;
                        $parenntdebitTotal = 0;
                        $parenttotalAmount = 0;

                        foreach ($parentAccs as $account) {
                            $Balance = $account['totalCredit'] - $account['totalDebit'];
                            $parenttotalBalance += $Balance;

                            $data = [
                                'account_id' => $account['id'],
                                'account_code' => $account['code'],
                                'account_name' => $account['name'],
                                'account' => 'parent',
                                'totalCredit' => 0,
                                'totalDebit' => 0,
                                'netAmount' => $Balance,
                            ];

                            $parentAccountArray[] = $data;
                            $parentcreditTotal += $data['totalCredit'];
                            $parenntdebitTotal += $data['totalDebit'];
                            $parenttotalAmount += $data['netAmount'];
                        }

                        foreach ($accounts as $account) {
                            $Balance = $account['totalCredit'] - $account['totalDebit'];
                            $parenttotalBalance += $Balance;

                            if ($Balance != 0) {
                                $data = [
                                    'account_id' => $account['id'],
                                    'account_code' => $account['code'],
                                    'account_name' => $account['name'],
                                    'account' => 'subAccount',
                                    'totalCredit' => 0,
                                    'totalDebit' => 0,
                                    'netAmount' => $Balance,
                                ];

                                $parentAccountArray[] = $data;
                                $parentcreditTotal += $data['totalCredit'];
                                $parenntdebitTotal += $data['totalDebit'];
                                $parenttotalAmount += $data['netAmount'];
                            }
                        }

                        if (!empty($parentAccountArray)) {
                            $dataTotal = [
                                'account_id' => $parentAccount->account,
                                'account_code' => '',
                                'account' => 'parentTotal',
                                'account_name' => 'Total ' . $parentAccount->name,
                                'totalCredit' => $parentcreditTotal,
                                'totalDebit' => $parenntdebitTotal,
                                'netAmount' => $parenttotalAmount,
                            ];

                            $parentAccountArrayTotal[] = $dataTotal;
                            $totalArray = array_merge($parentAccountArray, $parentAccountArrayTotal);
                            $totalParentAccountArray[] = $totalArray;
                        }

                    }

                }

                if ($totalParentAccountArray != []) {
                    $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $accounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.name', 'chart_of_account_parents.name');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('chart_of_account_parents.account');
                    $accounts->where('chart_of_accounts.parent', '=', 'chart_of_account_parents.id');
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();
                } else {
                    $accounts = TransactionLines::select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', \DB::raw('sum(debit) as totalDebit'), \DB::raw('sum(credit) as totalCredit'));
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();
                }

                $totalBalance = 0;
                $creditTotal = 0;
                $debitTotal = 0;
                $totalAmount = 0;
                $accountArray = [];
                foreach ($accounts as $account) {
                    $Balance = $account['totalCredit'] - $account['totalDebit'];
                    $totalBalance += $Balance;
                    if ($Balance != 0) {
                        $data['account_id'] = $account['id'];
                        $data['account_code'] = $account['code'];
                        $data['account_name'] = $account['name'];
                        $data['account'] = '';
                        $data['totalCredit'] = 0;
                        $data['totalDebit'] = 0;
                        $data['netAmount'] = $Balance;
                        $accountArray[][] = $data;
                        $creditTotal += $data['totalCredit'];
                        $debitTotal += $data['totalDebit'];
                        $totalAmount += $data['netAmount'];
                    }
                }

                $totalAccountArray = [];

                if ($accountArray != []) {
                    $dataTotal['account_id'] = '';
                    $dataTotal['account_code'] = '';
                    $dataTotal['account'] = '';
                    $dataTotal['account_name'] = 'Total ' . $type->name;
                    $dataTotal['totalCredit'] = $creditTotal;
                    $dataTotal['totalDebit'] = $debitTotal;
                    $dataTotal['netAmount'] = $totalAmount;
                    $accountArray[][] = $dataTotal;
                    $totalAccountArray = array_merge($totalParentAccountArray, $accountArray);

                } elseif ($totalParentAccountArray != []) {

                    $dataTotal['account_id'] = '';
                    $dataTotal['account_code'] = '';
                    $dataTotal['account'] = '';
                    $dataTotal['account_name'] = 'Total ' . $type->name;
                    $dataTotal['totalCredit'] = $creditTotal;
                    $dataTotal['totalDebit'] = $debitTotal;
                    $netAmount = 0;
                    foreach ($totalParentAccountArray as $innerArray) {
                        $lastElement = end($innerArray);

                        $netAmount += $lastElement['netAmount'];
                    }
                    $dataTotal['netAmount'] = $netAmount;
                    $accountArrayTotal[][] = $dataTotal;
                    $totalAccountArray = array_merge($totalParentAccountArray, $accountArrayTotal);
                }
                if ($totalAccountArray != []) {
                    $subTypeData['Type'] = ($totalAccountArray != []) ? $type->name : '';
                    $subTypeData['account'] = $totalAccountArray;
                    $subTypeArray[] = ($subTypeData['account'] != []) ? $subTypeData : [];
                }
                $totalAccounts = $subTypeArray;
            }
            $companyName = User::where('id', \Auth::user()->creatorId())->first();
            $companyName = $companyName->name;

            $name = 'profit & loss_' . date('Y-m-d i:h:s');
            $data = Excel::download(new ProfitLossExport($totalAccounts, $start, $end, $companyName), $name . '.xlsx');
            ob_end_clean();

            return $data;
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function salesReport(Request $request)
    {
        $creatorId = \Auth::user()->creatorId();
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-01-01');
            $end = date('Y-m-d');
        }
        $branchId = $request->get('branch_id');
        $customerId = $request->get('customer_id');

        $branches = Branch::where('created_by', $creatorId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        $baseInvoiceQuery = Invoice::query()
            ->where('invoices.created_by', $creatorId)
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end);
        if (!empty($branchId) && $branchId !== 'all') {
            $baseInvoiceQuery->whereHas('pos', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }
        if (!empty($customerId) && $customerId !== 'all') {
            if ($customerId == 'walk_in') {
                $baseInvoiceQuery->where('invoices.customer_id',0);
            } else {
                $baseInvoiceQuery->where('invoices.customer_id', $customerId);
            }
        }

        $filteredInvoiceIds = (clone $baseInvoiceQuery)->pluck('id')->toArray();
        $hasInvoices = count($filteredInvoiceIds) > 0;

        $totalSales = 0;
        $totalTransactions = count($filteredInvoiceIds);
        if ($totalTransactions > 0) {
            $invoicesForTotal = Invoice::with('items')->whereIn('id', $filteredInvoiceIds)->get();
            $totalSales = $invoicesForTotal->sum(fn ($inv) => $inv->getTotal());
        }
        $avgTransaction = $totalTransactions > 0 ? ($totalSales / $totalTransactions) : 0;

        $salesTrendDaily = collect();
        $salesTrendMonthly = collect();
        if ($hasInvoices) {
            $salesTrendDaily = \DB::table('invoices')
                ->join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
                ->whereIn('invoices.id', $filteredInvoiceIds)
                ->selectRaw('invoices.issue_date as date, SUM(invoice_products.price * invoice_products.quantity - COALESCE(invoice_products.discount, 0)) as amount')
                ->groupBy('invoices.issue_date')
                ->orderBy('invoices.issue_date')
                ->get()
                ->keyBy('date');
            $salesTrendMonthly = \DB::table('invoices')
                ->join('invoice_products', 'invoices.id', '=', 'invoice_products.invoice_id')
                ->whereIn('invoices.id', $filteredInvoiceIds)
                ->selectRaw("DATE_FORMAT(invoices.issue_date, '%Y-%m') as month, SUM(invoice_products.price * invoice_products.quantity - COALESCE(invoice_products.discount, 0)) as amount")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');
        }

        $topSellingProducts = [];
        $invoiceItems = [];
        if ($hasInvoices) {
            $topSellingProducts = InvoiceProduct::select('product_services.name', \DB::raw('sum(invoice_products.quantity) as quantity'), \DB::raw('sum(invoice_products.price * invoice_products.quantity) as price'))
                ->leftJoin('product_services', 'product_services.id', 'invoice_products.product_id')
                ->leftJoin('invoices', 'invoices.id', 'invoice_products.invoice_id')
                ->whereIn('invoice_products.invoice_id', $filteredInvoiceIds)
                ->where('product_services.created_by', $creatorId)
                ->groupBy('invoice_products.product_id', 'product_services.name')
                ->orderByDesc('price')
                ->limit(10)
                ->get()
                ->toArray();

            $invoiceItems = InvoiceProduct::select('product_services.name', \DB::raw('sum(invoice_products.quantity) as quantity'), \DB::raw('sum(invoice_products.price * invoice_products.quantity) as price'), \DB::raw('sum(invoice_products.price)/sum(invoice_products.quantity) as avg_price'))
                ->leftJoin('product_services', 'product_services.id', 'invoice_products.product_id')
                ->leftJoin('invoices', 'invoices.id', 'invoice_products.invoice_id')
                ->whereIn('invoice_products.invoice_id', $filteredInvoiceIds)
                ->where('product_services.created_by', $creatorId)
                ->groupBy('invoice_products.product_id')
                ->get()
                ->toArray();
        }

        $invoiceCustomeres = [];
        if ($hasInvoices) {
            $invoiceCustomeres = Invoice::select('customers.name', \DB::raw('count(DISTINCT invoices.id) as invoice_count'))
                ->selectRaw('sum((invoice_products.price * invoice_products.quantity) - COALESCE(invoice_products.discount, 0)) as price')
                ->selectRaw('(SELECT SUM((price * quantity - COALESCE(discount,0)) * (COALESCE(taxes.rate,0) / 100)) FROM invoice_products ip2 LEFT JOIN taxes ON FIND_IN_SET(taxes.id, ip2.tax) > 0 WHERE ip2.invoice_id = invoices.id) as total_tax')
                ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
                ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
                ->whereIn('invoices.id', $filteredInvoiceIds)
                ->groupBy('invoices.id', 'customers.name')
                ->get()
                ->toArray();
        }
        $mergedArray = [];
        foreach ($invoiceCustomeres as $item) {
            $name = $item['name'] ?? __('Walk-in Customer');
            if (!isset($mergedArray[$name])) {
                $mergedArray[$name] = ['name' => $name, 'invoice_count' => 0, 'price' => 0.0, 'total_tax' => 0.0];
            }
            $mergedArray[$name]['invoice_count'] += $item['invoice_count'];
            $mergedArray[$name]['price'] += (float) $item['price'];
            $mergedArray[$name]['total_tax'] += (float) ($item['total_tax'] ?? 0);
        }
        $invoiceCustomers = array_values($mergedArray);

        $filter = [
            'startDateRange' => $start,
            'endDateRange'   => $end,
            'branch_id'      => $branchId ?? 'all',
            'customer_id'    => $customerId ?? 'all',
        ];

        $salesTrendDailyJson = $salesTrendDaily->isEmpty() ? '[]' : $salesTrendDaily->map(fn ($r) => ['date' => $r->date, 'amount' => (float) $r->amount])->values()->toJson();
        $salesTrendMonthlyJson = $salesTrendMonthly->isEmpty() ? '[]' : $salesTrendMonthly->map(fn ($r) => ['month' => $r->month, 'amount' => (float) $r->amount])->values()->toJson();

        return view('report.sales_report', compact(
            'filter', 'invoiceItems', 'invoiceCustomers', 'branches', 'customers',
            'totalSales', 'totalTransactions', 'avgTransaction',
            'salesTrendDaily', 'salesTrendMonthly', 'salesTrendDailyJson', 'salesTrendMonthlyJson',
            'topSellingProducts'
        ));
    }

    public function salesReportExport(Request $request)
    {
        $creatorId = \Auth::user()->creatorId();
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-01-01');
            $end = date('Y-m-d');
        }
        $branchId = $request->get('branch_id');
        $customerId = $request->get('customer_id');

        $baseInvoiceQuery = Invoice::query()
            ->where('invoices.created_by', $creatorId)
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end);
        if (!empty($branchId) && $branchId !== 'all') {
            $baseInvoiceQuery->whereHas('pos', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }
        if (!empty($customerId) && $customerId !== 'all') {
            if ($customerId === 'walk_in') {
                $baseInvoiceQuery->whereNull('invoices.customer_id');
            } else {
                $baseInvoiceQuery->where('invoices.customer_id', $customerId);
            }
        }
        $filteredInvoiceIds = (clone $baseInvoiceQuery)->pluck('id')->toArray();

        if ($request->report == '#customer') {
            $reportName = 'Customer';
            $invoiceCustomeres = [];
            if (count($filteredInvoiceIds) > 0) {
                $invoiceCustomeres = Invoice::select('customers.name', \DB::raw('count(DISTINCT invoices.id) as invoice_count'))
                    ->selectRaw('sum((invoice_products.price * invoice_products.quantity) - COALESCE(invoice_products.discount, 0)) as price')
                    ->selectRaw('(SELECT SUM((price * quantity - COALESCE(discount,0)) * (COALESCE(taxes.rate,0) / 100)) FROM invoice_products ip2 LEFT JOIN taxes ON FIND_IN_SET(taxes.id, ip2.tax) > 0 WHERE ip2.invoice_id = invoices.id) as total_tax')
                    ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
                    ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
                    ->whereIn('invoices.id', $filteredInvoiceIds)
                    ->groupBy('invoices.id', 'customers.name')
                    ->get()
                    ->toArray();
            }
            $mergedArray = [];
            foreach ($invoiceCustomeres as $item) {
                $name = $item['name'] ?? __('Walk-in Customer');
                if (!isset($mergedArray[$name])) {
                    $mergedArray[$name] = ['name' => $name, 'invoice_count' => 0, 'price' => 0.0, 'total_tax' => 0.0];
                }
                $mergedArray[$name]['invoice_count'] += $item['invoice_count'];
                $mergedArray[$name]['price'] += (float) $item['price'];
                $mergedArray[$name]['total_tax'] += (float) ($item['total_tax'] ?? 0);
            }
            $invoiceItems = array_values($mergedArray);
        } else {
            $reportName = 'Item';
            $invoiceItems = [];
            if (count($filteredInvoiceIds) > 0) {
                $invoiceItems = InvoiceProduct::select('product_services.name', \DB::raw('sum(invoice_products.quantity) as quantity'), \DB::raw('sum(invoice_products.price * invoice_products.quantity) as price'), \DB::raw('sum(invoice_products.price)/sum(invoice_products.quantity) as avg_price'))
                    ->leftJoin('product_services', 'product_services.id', 'invoice_products.product_id')
                    ->leftJoin('invoices', 'invoices.id', 'invoice_products.invoice_id')
                    ->whereIn('invoice_products.invoice_id', $filteredInvoiceIds)
                    ->where('product_services.created_by', $creatorId)
                    ->groupBy('invoice_products.product_id')
                    ->get()
                    ->toArray();
            }
        }

        $companyName = User::where('id', $creatorId)->first();
        $companyName = $companyName ? $companyName->name : '';

        $name = 'Sales_By_' . $reportName . '_' . date('Y-m-d_His');
        $data = Excel::download(new SalesReportExport($invoiceItems, $start, $end, $companyName, $reportName), $name . '.xlsx');
        ob_end_clean();

        return $data;
    }

    public function ReceivablesReport(Request $request)
    {
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-01-01');
            $end = date('Y-m-d', strtotime('+1 day'));
        }

        $receivableCustomers = Invoice::select('customers.name')
            ->selectRaw('sum((invoice_products.price * invoice_products.quantity) - invoice_products.discount) as price')
            ->selectRaw('sum((invoice_payments.amount)) as pay_price')
            ->selectRaw('(SELECT SUM((price * quantity - discount) * (taxes.rate / 100)) FROM invoice_products
             LEFT JOIN taxes ON FIND_IN_SET(taxes.id, invoice_products.tax) > 0
             WHERE invoice_products.invoice_id = invoices.id) as total_tax')
            ->selectRaw('(SELECT SUM(credit_notes.amount) FROM credit_notes
             WHERE credit_notes.invoice = invoices.id) as credit_price')
            ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
            ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', 'invoices.id')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end)
            ->groupBy('invoices.invoice_id')
            ->get()
            ->toArray();

        $receivableSummariesInvoice = Invoice::select('customers.name')
            ->selectRaw('(invoices.invoice_id) as invoice')
            ->selectRaw('sum((invoice_products.price * invoice_products.quantity) - invoice_products.discount) as price')
            ->selectRaw('sum((invoice_payments.amount)) as pay_price')
            ->selectRaw('(SELECT SUM((price * quantity - discount) * (taxes.rate / 100)) FROM invoice_products
             LEFT JOIN taxes ON FIND_IN_SET(taxes.id, invoice_products.tax) > 0
             WHERE invoice_products.invoice_id = invoices.id) as total_tax')
            ->selectRaw('invoices.issue_date as issue_date')
            ->selectRaw('invoices.status as status')
            ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
            ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', 'invoices.id')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end)
            ->groupBy('invoices.invoice_id')
            ->get()
            ->toArray();

        $receivableSummariesCredit = CreditNote::select('customers.name')
            ->selectRaw('null as invoice')
            ->selectRaw('(credit_notes.amount) as price')
            ->selectRaw('0 as pay_price')
            ->selectRaw('0 as total_tax')
            ->selectRaw('credit_notes.date as issue_date')
            ->selectRaw('5 as status')
            ->leftJoin('customers', 'customers.id', 'credit_notes.customer')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'credit_notes.invoice')
            ->leftJoin('invoices', 'invoices.id', 'credit_notes.invoice')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('credit_notes.date', '>=', $start)
            ->where('credit_notes.date', '<=', $end)
            ->groupBy('credit_notes.id')
            ->get()
            ->toArray();

        $receivableSummaries = (array_merge($receivableSummariesCredit, $receivableSummariesInvoice));

        $receivableDetailsInvoice = Invoice::select('customers.name')
            ->selectRaw('(invoices.invoice_id) as invoice')
            ->selectRaw('sum(invoice_products.price) as price')
            ->selectRaw('(invoice_products.quantity) as quantity')
            ->selectRaw('(product_services.name) as product_name')
            ->selectRaw('invoices.issue_date as issue_date')
            ->selectRaw('invoices.status as status')
            ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
            ->leftJoin('product_services', 'product_services.id', 'invoice_products.product_id')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end)
            ->groupBy('invoices.invoice_id', 'product_services.name')
            ->get()
            ->toArray();

        $receivableDetailsCredit = CreditNote::select('customers.name')
            ->selectRaw('null as invoice')
            ->selectRaw('(credit_notes.id) as invoices')
            ->selectRaw('(credit_notes.amount) as price')
            ->selectRaw('(product_services.name) as product_name')
            ->selectRaw('credit_notes.date as issue_date')
            ->selectRaw('5 as status')
            ->leftJoin('customers', 'customers.id', 'credit_notes.customer')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'credit_notes.invoice')
            ->leftJoin('product_services', 'product_services.id', 'invoice_products.product_id')
            ->leftJoin('invoices', 'invoices.id', 'credit_notes.invoice')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('credit_notes.date', '>=', $start)
            ->where('credit_notes.date', '<=', $end)
            ->groupBy('credit_notes.id', 'product_services.name')
            ->get()
            ->toArray();

        $mergedArray = [];
        foreach ($receivableDetailsCredit as $item) {
            $invoices = $item["invoices"];

            if (!isset($mergedArray[$invoices])) {
                $mergedArray[$invoices] = [
                    "name" => $item["name"],
                    "invoice" => $item["invoice"],
                    "invoices" => $invoices,
                    "price" => $item["price"],
                    "quantity" => 0,
                    "product_name" => "",
                    "issue_date" => "",
                    "status" => 0,
                ];
            }

            if (!strstr($mergedArray[$invoices]["product_name"], $item["product_name"])) {
                if ($mergedArray[$invoices]["product_name"] !== "") {
                    $mergedArray[$invoices]["product_name"] .= ", ";
                }
                $mergedArray[$invoices]["product_name"] .= $item["product_name"];
            }

            $mergedArray[$invoices]["issue_date"] = $item["issue_date"];
            $mergedArray[$invoices]["status"] = $item["status"];
        }

        $receivableDetailsCredits = array_values($mergedArray);

        $receivableDetails = (array_merge($receivableDetailsInvoice, $receivableDetailsCredits));

        $agingSummary = Invoice::select('customers.name', 'invoices.due_date as due_date', 'invoices.status as status', 'invoices.invoice_id as invoice_id')
            ->selectRaw('sum((invoice_products.price * invoice_products.quantity) - invoice_products.discount) as price')
            ->selectRaw('sum((invoice_payments.amount)) as pay_price')
            ->selectRaw('(SELECT SUM((price * quantity - discount) * (taxes.rate / 100)) FROM invoice_products
             LEFT JOIN taxes ON FIND_IN_SET(taxes.id, invoice_products.tax) > 0
             WHERE invoice_products.invoice_id = invoices.id) as total_tax')
            ->selectRaw('(SELECT SUM(credit_notes.amount) FROM credit_notes
             WHERE credit_notes.invoice = invoices.id) as credit_price')
            ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
            ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', 'invoices.id')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end)
            ->groupBy('invoices.invoice_id')
            ->get()
            ->toArray();

        $agingSummaries = [];

        $today = date("Y-m-d");
        foreach ($agingSummary as $item) {
            $name = $item["name"];
            $price = floatval(($item["price"] + $item['total_tax']) - ($item['pay_price'] + $item['credit_price']));
            $dueDate = $item["due_date"];

            if (!isset($agingSummaries[$name])) {
                $agingSummaries[$name] = [
                    'current' => 0.0,
                    "1_15_days" => 0.0,
                    "16_30_days" => 0.0,
                    "31_45_days" => 0.0,
                    "greater_than_45_days" => 0.0,
                    "total_due" => 0.0,
                ];
            }

            $daysDifference = date_diff(date_create($dueDate), date_create($today));
            $daysDifference = $daysDifference->format("%R%a");

            if ($daysDifference <= 0) {
                $agingSummaries[$name]["current"] += $price;
            } elseif ($daysDifference >= 1 && $daysDifference <= 15) {
                $agingSummaries[$name]["1_15_days"] += $price;
            } elseif ($daysDifference >= 16 && $daysDifference <= 30) {
                $agingSummaries[$name]["16_30_days"] += $price;
            } elseif ($daysDifference >= 31 && $daysDifference <= 45) {
                $agingSummaries[$name]["31_45_days"] += $price;
            } elseif ($daysDifference > 45) {
                $agingSummaries[$name]["greater_than_45_days"] += $price;
            }

            $agingSummaries[$name]["total_due"] += $price;
        }

        $currents = [];
        $days1to15 = [];
        $days16to30 = [];
        $days31to45 = [];
        $moreThan45 = [];

        foreach ($agingSummary as $item) {
            $dueDate = $item["due_date"];
            $price = floatval($item["price"]);
            $total_tax = floatval($item["total_tax"]);
            $credit_price = floatval($item["credit_price"]);
            $payPrice = $item["pay_price"] ? floatval($item["pay_price"]) : 0;

            $daysDifference = date_diff(date_create($dueDate), date_create($today));
            $daysDifference = $daysDifference->format("%R%a");
            $balanceDue = ($price + $total_tax) - ($payPrice + $credit_price);
            $totalPrice = $price + $total_tax;
            if ($daysDifference <= 0) {
                $item["total_price"] = $totalPrice;
                $item["balance_due"] = $balanceDue;
                $currents[] = $item;
            } elseif ($daysDifference >= 1 && $daysDifference <= 15) {
                $item["total_price"] = $totalPrice;
                $item["balance_due"] = $balanceDue;
                $item['age'] = intval(str_replace(array('+', '-'), '', $daysDifference));
                $days1to15[] = $item;
            } elseif ($daysDifference >= 16 && $daysDifference <= 30) {
                $item["total_price"] = $totalPrice;
                $item["balance_due"] = $balanceDue;
                $item['age'] = intval(str_replace(array('+', '-'), '', $daysDifference));
                $days16to30[] = $item;
            } elseif ($daysDifference >= 31 && $daysDifference <= 45) {
                $item["total_price"] = $totalPrice;
                $item["balance_due"] = $balanceDue;
                $item['age'] = intval(str_replace(array('+', '-'), '', $daysDifference));
                $days31to45[] = $item;
            } else {
                $item["total_price"] = $totalPrice;
                $item["balance_due"] = $balanceDue;
                $item['age'] = intval(str_replace(array('+', '-'), '', $daysDifference));
                $moreThan45[] = $item;
            }
        }

        $filter['startDateRange'] = $start;
        $filter['endDateRange'] = $end;

        return view('report.receivable_report', compact('filter', 'receivableCustomers', 'receivableSummaries', 'receivableDetails', 'agingSummaries', 'currents', 'days1to15', 'days16to30', 'days31to45', 'moreThan45'));
    }

    public function ReceivablesExport(Request $request)
    {
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-01-01');
            $end = date('Y-m-d', strtotime('+1 day'));
        }

        $receivableCustomers = Invoice::select('customers.name')
            ->selectRaw('sum((invoice_products.price * invoice_products.quantity) - invoice_products.discount) as price')
            ->selectRaw('sum((invoice_payments.amount)) as pay_price')
            ->selectRaw('(SELECT SUM((price * quantity - discount) * (taxes.rate / 100)) FROM invoice_products
             LEFT JOIN taxes ON FIND_IN_SET(taxes.id, invoice_products.tax) > 0
             WHERE invoice_products.invoice_id = invoices.id) as total_tax')
            ->selectRaw('(SELECT SUM(credit_notes.amount) FROM credit_notes
             WHERE credit_notes.invoice = invoices.id) as credit_price')
            ->leftJoin('customers', 'customers.id', 'invoices.customer_id')
            ->leftJoin('invoice_payments', 'invoice_payments.invoice_id', 'invoices.id')
            ->leftJoin('invoice_products', 'invoice_products.invoice_id', 'invoices.id')
            ->where('invoices.created_by', \Auth::user()->creatorId())
            ->where('invoices.issue_date', '>=', $start)
            ->where('invoices.issue_date', '<=', $end)
            ->groupBy('invoices.invoice_id')
            ->get()
            ->toArray();

        $companyName = User::where('id', \Auth::user()->creatorId())->first();
        $companyName = $companyName->name;

        $name = 'Receivable Report_ ' . date('Y-m-d i:h:s');
        $data = Excel::download(new ReceivableExport($receivableCustomers, $start, $end, $companyName), $name . '.xlsx');
        ob_end_clean();

        return $data;
    }

    public function PayablesReport(Request $request)
    {
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            $start = date('Y-01-01');
            $end = date('Y-m-d', strtotime('+1 day'));
        }

        $payableVendors = Bill::select('venders.name')
            ->selectRaw('sum((bill_products.price * bill_products.quantity) - bill_products.discount) as price')
            ->selectRaw('sum((bill_payments.amount)) as pay_price')
            ->selectRaw('sum((bill_accounts.price)) as account_price')
            ->selectRaw('(SELECT SUM((price * quantity - discount) * (taxes.rate / 100)) FROM bill_products
         LEFT JOIN taxes ON FIND_IN_SET(taxes.id, bill_products.tax) > 0
         WHERE bill_products.bill_id = bills.id) as total_tax')
            ->selectRaw('(SELECT SUM(debit_notes.amount) FROM debit_notes
         WHERE debit_notes.bill = bills.id) as debit_price')
            ->leftJoin('venders', 'venders.id', 'bills.vender_id')
            ->leftJoin('bill_payments', 'bill_payments.bill_id', 'bills.id')
            ->leftJoin('bill_products', 'bill_products.bill_id', 'bills.id')
            ->leftJoin('bill_accounts', 'bill_accounts.ref_id', 'bills.id')
            ->where('bills.created_by', \Auth::user()->creatorId())
            ->whereNotIn('bills.user_type', ['employee', 'customer'])
            ->where('bills.bill_date', '>=', $start)
            ->where('bills.bill_date', '<=', $end)
            ->groupBy('bills.bill_id')
            ->get()
            ->toArray();

        $payableSummariesBill = Bill::select('venders.name')
            ->selectRaw('(bills.bill_id) as bill')
            ->selectRaw('(bills.type) as type')
            ->selectRaw('sum((bill_accounts.price)) as account_price')
            ->selectRaw('sum((bill_products.price * bill_products.quantity) - bill_products.discount) as price')
            ->selectRaw('sum((bill_payments.amount)) as pay_price')
            ->selectRaw('(SELECT SUM((price * quantity - discount) * (taxes.rate / 100)) FROM bill_products
         LEFT JOIN taxes ON FIND_IN_SET(taxes.id, bill_products.tax) > 0
         WHERE bill_products.bill_id = bills.id) as total_tax')
            ->selectRaw('bills.bill_date as bill_date')
            ->selectRaw('bills.status as status')
            ->leftJoin('venders', 'venders.id', 'bills.vender_id')
            ->leftJoin('bill_payments', 'bill_payments.bill_id', 'bills.id')
            ->leftJoin('bill_products', 'bill_products.bill_id', 'bills.id')
            ->leftJoin('bill_accounts', 'bill_accounts.ref_id', 'bills.id')
            ->where('bills.created_by', \Auth::user()->creatorId())
            ->whereNotIn('bills.user_type', ['employee', 'customer'])
            ->where('bills.bill_date', '>=', $start)
            ->where('bills.bill_date', '<=', $end)
            ->groupBy('bills.id')
            ->get()
            ->toArray();

        $payableSummariesDebit = DebitNote::select('venders.name')
            ->selectRaw('null as bill')
            ->selectRaw('debit_notes.amount as price')
            ->selectRaw('0 as pay_price')
            ->selectRaw('0 as total_tax')
            ->selectRaw('debit_notes.date as bill_date')
            ->selectRaw('5 as status')
            ->leftJoin('venders', 'venders.id', 'debit_notes.vendor')
            ->leftJoin('bill_products', 'bill_products.bill_id', 'debit_notes.bill')
            ->leftJoin('bills', 'bills.id', 'debit_notes.bill')
            ->where('bills.created_by', \Auth::user()->creatorId())
            ->where('debit_notes.date', '>=', $start)
            ->where('debit_notes.date', '<=', $end)
            ->groupBy('debit_notes.id')
            ->get()
            ->toArray();

        $payableSummaries = (array_merge($payableSummariesDebit, $payableSummariesBill));

        $payableDetailsBill = Bill::select('venders.name')
            ->selectRaw('(bills.bill_id) as bill')
            ->selectRaw('(bills.type) as type')
            ->selectRaw('sum(bill_products.price) as price')
            ->selectRaw('(bill_products.quantity) as quantity')
            ->selectRaw('(product_services.name) as product_name')
            ->selectRaw('bills.bill_date as bill_date')
            ->selectRaw('bills.status as status')
            ->leftJoin('venders', 'venders.id', 'bills.vender_id')
            ->leftJoin('bill_products', 'bill_products.bill_id', 'bills.id')
            ->leftJoin('product_services', 'product_services.id', 'bill_products.product_id')
            ->where('bills.created_by', \Auth::user()->creatorId())
            ->whereNotIn('bills.user_type', ['employee', 'customer'])
            ->where('bills.bill_date', '>=', $start)
            ->where('bills.bill_date', '<=', $end)
            ->groupBy('bills.bill_id', 'product_services.name')
            ->get()
            ->toArray();

        $payableDetailsDebit = DebitNote::select('venders.name')
            ->selectRaw('null as bill')
            ->selectRaw('(debit_notes.id) as bills')
            ->selectRaw('(debit_notes.amount) as price')
            ->selectRaw('(product_services.name) as product_name')
            ->selectRaw('debit_notes.date as bill_date')
            ->selectRaw('5 as status')
            ->leftJoin('venders', 'venders.id', 'debit_notes.vendor')
            ->leftJoin('bill_products', 'bill_products.bill_id', 'debit_notes.bill')
            ->leftJoin('product_services', 'product_services.id', 'bill_products.product_id')
            ->leftJoin('bills', 'bills.id', 'debit_notes.bill')
            ->where('bills.created_by', \Auth::user()->creatorId())
            ->where('debit_notes.date', '>=', $start)
            ->where('debit_notes.date', '<=', $end)
            ->groupBy('debit_notes.id', 'product_services.name')
            ->get()
            ->toArray();

        $mergedArray = [];
        foreach ($payableDetailsDebit as $item) {
            $invoices = $item["bills"];

            if (!isset($mergedArray[$invoices])) {
                $mergedArray[$invoices] = [
                    "name" => $item["name"],
                    "bill" => $item["bill"],
                    "bills" => $invoices,
                    "price" => $item["price"],
                    "quantity" => 0,
                    "product_name" => "",
                    "bill_date" => "",
                    "status" => 0,
                ];
            }

            if (!strstr($mergedArray[$invoices]["product_name"], $item["product_name"])) {
                if ($mergedArray[$invoices]["product_name"] !== "") {
                    $mergedArray[$invoices]["product_name"] .= ", ";
                }
                $mergedArray[$invoices]["product_name"] .= $item["product_name"];
            }

            $mergedArray[$invoices]["bill_date"] = $item["bill_date"];
            $mergedArray[$invoices]["status"] = $item["status"];
        }

        $payableDetailsDebits = array_values($mergedArray);

        $payableDetails = (array_merge($payableDetailsBill, $payableDetailsDebits));

        $filter['startDateRange'] = $start;
        $filter['endDateRange'] = $end;

        return view('report.payable_report', compact('filter', 'payableVendors', 'payableSummaries', 'payableDetails'));
    }

}
