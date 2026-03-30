<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountType;
use App\Models\TransactionLines;
use Illuminate\Http\Request;

class MonthlyReportController extends Controller
{
    public function trialBalance(Request $request, $view = 'government')
    {
        if (!\Auth::user()->can('trial balance report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        if (!empty($request->start_date) && !empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } else {
            // Default: current year to today (inclusive)
            $start = date('Y-01-01');
            $end = date('Y-m-d');
        }

        $types = ChartOfAccountType::where('created_by', \Auth::user()->creatorId())->get();
        $totalAccounts = [];
        $totalAccount = [];

        foreach ($types as $type) {
            $parentAccounts = ChartOfAccountParent::where('type', $type->id)
                ->where('created_by', \Auth::user()->creatorId())
                ->get();

            $totalParentAccountArray = [];

            if ($parentAccounts->isNotEmpty()) {
                foreach ($parentAccounts as $parentAccount) {
                    $parentAccountArray = [];
                    $parentAccountArrayTotal = [];

                    $parentAccs = TransactionLines::select(
                        'chart_of_accounts.id',
                        'chart_of_accounts.code',
                        'chart_of_accounts.name',
                        \DB::raw('sum(debit) as totalDebit'),
                        \DB::raw('sum(credit) as totalCredit')
                    );
                    $parentAccs->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $parentAccs->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
                    $parentAccs->where('chart_of_accounts.type', $type->id);
                    $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                    $parentAccs->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $parentAccs->where('transaction_lines.date', '>=', $start);
                    $parentAccs->where('transaction_lines.date', '<=', $end);
                    $parentAccs->groupBy('account_id');
                    $parentAccs = $parentAccs->get()->toArray();

                    $accounts = TransactionLines::select(
                        'chart_of_accounts.id',
                        'chart_of_accounts.code',
                        'chart_of_accounts.name',
                        \DB::raw('sum(debit) as totalDebit'),
                        \DB::raw('sum(credit) as totalCredit')
                    );
                    $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
                    $accounts->where('chart_of_accounts.type', $type->id);
                    $accounts->where('chart_of_accounts.parent', $parentAccount->id);
                    $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
                    $accounts->where('transaction_lines.date', '>=', $start);
                    $accounts->where('transaction_lines.date', '<=', $end);
                    $accounts->groupBy('account_id');
                    $accounts = $accounts->get()->toArray();

                    if ($parentAccs == [] && $accounts != []) {
                        // Keep behavior consistent with ReportController: show parent with zeros if it exists but has no direct txns
                        $parentAccs = \App\Models\ChartOfAccount::select(
                            'chart_of_accounts.id',
                            'chart_of_accounts.code',
                            'chart_of_accounts.name',
                            \DB::raw('0 as totalDebit'),
                            \DB::raw('0 as totalCredit')
                        );
                        $parentAccs->leftjoin('chart_of_account_parents', 'chart_of_accounts.id', 'chart_of_account_parents.account');
                        $parentAccs->where('chart_of_accounts.type', $type->id);
                        $parentAccs->where('chart_of_accounts.name', $parentAccount->name);
                        $parentAccs->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
                        $parentAccs = $parentAccs->get()->toArray();
                    } elseif ($parentAccs != [] && $accounts == []) {
                        $parentAccs = [];
                    }

                    $parentcreditTotal = 0;
                    $parenntdebitTotal = 0;

                    foreach ($parentAccs as $account) {
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

            $accounts = TransactionLines::select(
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.code as account_code',
                'chart_of_accounts.name as account_name',
                \DB::raw('sum(debit) as totalDebit'),
                \DB::raw('sum(credit) as totalCredit')
            );
            $accounts->leftjoin('chart_of_accounts', 'transaction_lines.account_id', 'chart_of_accounts.id');
            $accounts->leftjoin('chart_of_account_types', 'chart_of_accounts.type', 'chart_of_account_types.id');
            $accounts->where('chart_of_accounts.type', $type->id);
            $accounts->where('transaction_lines.created_by', \Auth::user()->creatorId());
            $accounts->where('transaction_lines.date', '>=', $start);
            $accounts->where('transaction_lines.date', '<=', $end);
            $accounts->groupBy('account_id');
            $accounts = $accounts->get()->toArray();

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

        $filter = [
            'startDateRange' => $start,
            'endDateRange' => $end,
        ];

        // The "government" view is intentionally a separate Blade file so you can edit layout freely.
        $blade = $view === 'classic' ? 'report.trial_balance' : 'reports.monthly.trial_balance';

        return view($blade, compact('filter', 'totalAccounts', 'view'));
    }
}

