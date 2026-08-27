<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\TransactionLines;
use App\Models\Utility;
use App\Support\AccountingPeriodDate;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{

    public function index()
    {
        if (\Auth::user()->can('manage journal entry')) {
            $journalEntries = JournalEntry::where('created_by', '=', \Auth::user()->creatorId())->get();

            return view('journalEntry.index', compact('journalEntries'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create(Request $request)
    {
        if (\Auth::user()->can('create journal entry')) {
            $ulane = auth()->user()->lang ?? 'en';
            $name_column = 'name';
            switch($ulane)
            {
                case 'en':
                    $name_column = 'name';
                    break;
                case 'fr':
                    $name_column = 'name_fr';
                    break;
                case 'zh':
                    $name_column = 'name_zh';
                    break;
                case 'es':
                    $name_column = 'name_es';
                    break;
                case 'pt':
                    $name_column = 'name_pt_br';
                    break;
                default:
                    $name_column = 'name';
                    break;
            }
            $chartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.'.$name_column.') AS code_name,chart_of_accounts.id, chart_of_accounts.code,  chart_of_accounts.parent'))
                ->where('parent', '=', 0)
                ->where('created_by', \Auth::user()->creatorId())->get()
                ->toarray();

            $subAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.'.$name_column.') AS code_name , chart_of_accounts.id, chart_of_accounts.code , chart_of_account_parents.account'));
            $subAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $subAccounts->where('chart_of_accounts.parent', '!=', 0);
            $subAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $subAccounts = $subAccounts->get()->toArray();

            $journalId = $this->journalNumber();
            $defaultAccountingPeriod = $request->query('accounting_period', '');
            $defaultDate = $request->query('date');

            return view('journalEntry.create', compact(
                'chartAccounts',
                'subAccounts',
                'journalId',
                'name_column',
                'defaultAccountingPeriod',
                'defaultDate',
            ));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {

        if (\Auth::user()->can('create journal entry')) {
            $validator = \Validator::make(
                $request->all(), [
                    'journal_year' => 'required|integer|min:2000|max:2100',
                    'journal_month' => 'required|integer|min:0|max:15',
                    'journal_day' => 'required|integer|min:1|max:31',
                    'accounts' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $accounts = $request->accounts;

            $totalDebit = 0;
            $totalCredit = 0;
            for ($i = 0; $i < count($accounts); $i++) {
                $debit = isset($accounts[$i]['debit']) ? $accounts[$i]['debit'] : 0;
                $credit = isset($accounts[$i]['credit']) ? $accounts[$i]['credit'] : 0;
                $totalDebit += $debit;
                $totalCredit += $credit;
            }
            // $totalDebit += $debit;

            if (round($totalCredit, 2) !== round($totalDebit, 2)) {
                return redirect()->back()->with('error', __('Debit and Credit must be Equal.'));
            }

            $periodDate = AccountingPeriodDate::fromRequest($request->all());

            $journal = new JournalEntry();
            $journal->journal_id = $this->journalNumber();
            $journal->date = $periodDate['date'];
            $journal->reference = $request->reference;
            $journal->description = $request->description;
            $journal->status = JournalEntry::STATUS_PENDING;
            $journal->created_by = \Auth::user()->creatorId();
            $journal->accounting_period = $periodDate['accounting_period'];
            $journal->save();

            for ($i = 0; $i < count($accounts); $i++) {
                $journalItem = new JournalItem();
                $journalItem->journal = $journal->id;
                $journalItem->account = $accounts[$i]['account'];
                $journalItem->description = $accounts[$i]['description'];
                $journalItem->debit = isset($accounts[$i]['debit']) ? $accounts[$i]['debit'] : 0;
                $journalItem->credit = isset($accounts[$i]['credit']) ? $accounts[$i]['credit'] : 0;
                $journalItem->save();

            }

            return redirect()->route('journal-entry.index')->with('success', __('Journal entry successfully created and is pending approval.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show(JournalEntry $journalEntry)
    {
        if (\Auth::user()->can('show journal entry')) {
            if ($journalEntry->created_by == \Auth::user()->creatorId()) {
                $accounts = $journalEntry->accounts;
                $settings = Utility::settings();
                    
                $ulane = auth()->user()->lang ?? 'en';
                $name_column = 'name';
                switch($ulane)
                {
                    case 'en':
                        $name_column = 'name';
                        break;
                    case 'fr':
                        $name_column = 'name_fr';
                        break;
                    case 'zh':
                        $name_column = 'name_zh';
                        break;
                    case 'es':
                        $name_column = 'name_es';
                        break;
                    case 'pt':
                        $name_column = 'name_pt_br';
                        break;
                    default:
                        $name_column = 'name';
                        break;
                }

                return view('journalEntry.view', compact('journalEntry', 'accounts', 'settings','name_column'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function edit(JournalEntry $journalEntry)
    {
        if (\Auth::user()->can('edit journal entry')) {
            $ulane = auth()->user()->lang ?? 'en';
            $name_column = 'name';
            switch($ulane)
            {
                case 'en':
                    $name_column = 'name';
                    break;
                case 'fr':
                    $name_column = 'name_fr';
                    break;
                case 'zh':
                    $name_column = 'name_zh';
                    break;
                case 'es':
                    $name_column = 'name_es';
                    break;
                case 'pt':
                    $name_column = 'name_pt_br';
                    break;
                default:
                    $name_column = 'name';
                    break;
            }
            $chartAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.'.$name_column.') AS code_name,chart_of_accounts.id, chart_of_accounts.code,  chart_of_accounts.parent'))
                ->where('parent', '=', 0)
                ->where('created_by', \Auth::user()->creatorId())->get()
                ->toarray();

            $subAccounts = ChartOfAccount::select(\DB::raw('CONCAT(chart_of_accounts.code, " - ", chart_of_accounts.'.$name_column.') AS code_name , chart_of_accounts.id, chart_of_accounts.code , chart_of_account_parents.account'));
            $subAccounts->leftjoin('chart_of_account_parents', 'chart_of_accounts.parent', 'chart_of_account_parents.id');
            $subAccounts->where('chart_of_accounts.parent', '!=', 0);
            $subAccounts->where('chart_of_accounts.created_by', \Auth::user()->creatorId());
            $subAccounts = $subAccounts->get()->toArray();

            return view('journalEntry.edit', compact('chartAccounts', 'journalEntry' , 'subAccounts'));
        } else {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function update(Request $request, JournalEntry $journalEntry)
    {
        if (\Auth::user()->can('edit journal entry')) {
            if ($journalEntry->created_by == \Auth::user()->creatorId()) {
                $validator = \Validator::make(
                    $request->all(), [
                        'journal_year' => 'required|integer|min:2000|max:2100',
                        'journal_month' => 'required|integer|min:0|max:15',
                        'journal_day' => 'required|integer|min:1|max:31',
                        'accounts' => 'required',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }

                $accounts = $request->accounts;

                $totalDebit = 0;
                $totalCredit = 0;
                for ($i = 0; $i < count($accounts); $i++) {
                    $debit = isset($accounts[$i]['debit']) ? $accounts[$i]['debit'] : 0;
                    $credit = isset($accounts[$i]['credit']) ? $accounts[$i]['credit'] : 0;
                    $totalDebit += $debit;
                    $totalCredit += $credit;
                }

                if (round($totalCredit, 2) !== round($totalDebit, 2)) {
                    return redirect()->back()->with('error', __('Debit and Credit must be Equal.'));
                }

                $periodDate = AccountingPeriodDate::fromRequest($request->all());

                $journalEntry->date = $periodDate['date'];
                $journalEntry->reference = $request->reference;
                $journalEntry->description = $request->description;
                $journalEntry->created_by = \Auth::user()->creatorId();
                $journalEntry->accounting_period = $periodDate['accounting_period'];
                $journalEntry->save();

                for ($i = 0; $i < count($accounts); $i++) {
                    $journalItem = JournalItem::find($accounts[$i]['id']);

                    if ($journalItem == null) {
                        $journalItem = new JournalItem();
                        $journalItem->journal = $journalEntry->id;
                    }

                    if (isset($accounts[$i]['account'])) {
                        $journalItem->account = $accounts[$i]['account'];
                    }

                    $journalItem->description = $accounts[$i]['description'];
                    $journalItem->debit = isset($accounts[$i]['debit']) ? $accounts[$i]['debit'] : 0;
                    $journalItem->credit = isset($accounts[$i]['credit']) ? $accounts[$i]['credit'] : 0;
                    $journalItem->save();

                    if ($journalEntry->status == JournalEntry::STATUS_APPROVED) {
                        if (isset($accounts[$i]['debit'])) {
                            $data = [
                                'account_id' => $accounts[$i]['account'],
                                'transaction_type' => 'Debit',
                                'transaction_amount' => $accounts[$i]['debit'],
                                'reference' => 'Journal',
                                'reference_id' => $journalEntry->id,
                                'reference_sub_id' => $journalItem->id,
                                'date' => $journalEntry->date,
                            ];
                        } else {
                            $data = [
                                'account_id' => $accounts[$i]['account'],
                                'transaction_type' => 'Credit',
                                'transaction_amount' => $accounts[$i]['credit'],
                                'reference' => 'Journal',
                                'reference_id' => $journalEntry->id,
                                'reference_sub_id' => $journalItem->id,
                                'date' => $journalEntry->date,
                            ];
                        }
                        Utility::addTransactionLines($data , 'edit');
                    }
                }

                return redirect()->route('journal-entry.index')->with('success', __('Journal entry successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy(JournalEntry $journalEntry)
    {

        if (\Auth::user()->can('delete journal entry')) {
            if ($journalEntry->created_by == \Auth::user()->creatorId()) {
                $journalEntry->delete();

                JournalItem::where('journal', '=', $journalEntry->id)->delete();

                TransactionLines::where('reference_id', $journalEntry->id)->where('reference', 'Journal')->delete();

                return redirect()->route('journal-entry.index')->with('success', __('Journal entry successfully deleted.'));
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function journalNumber()
    {
        $latest = JournalEntry::where('created_by', '=', \Auth::user()->creatorId())->latest()->first();
        if (!$latest) {
            return 1;
        }

        return $latest->journal_id + 1;
    }

    public function accountDestroy(Request $request)
    {

        if (\Auth::user()->can('delete journal entry')) {
            JournalItem::where('id', '=', $request->id)->delete();

            return redirect()->back()->with('success', __('Journal entry account successfully deleted.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function journalDestroy($item_id)
    {
        if (\Auth::user()->can('delete journal entry')) {
            $journal = JournalItem::find($item_id);
            $journal->delete();

            return redirect()->back()->with('success', __('Journal account successfully deleted.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function approve(JournalEntry $journalEntry)
    {
        if (! \Auth::user()->can('approve journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($journalEntry->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($journalEntry->status != JournalEntry::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only pending journal entries can be approved.'));
        }

        $accounts = $journalEntry->accounts;

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($accounts as $journalItem) {
            $totalDebit += (float) $journalItem->debit;
            $totalCredit += (float) $journalItem->credit;
        }
        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            return redirect()->back()->with('error', __('Debit and Credit must be Equal.'));
        }

        foreach ($accounts as $journalItem) {
            $bankAccounts = BankAccount::where('chart_account_id', '=', $journalItem->account)->get();
            if (! empty($bankAccounts)) {
                foreach ($bankAccounts as $bankAccount) {
                    $old_balance = $bankAccount->opening_balance;
                    if ($journalItem->debit > 0) {
                        $new_balance = $old_balance - $journalItem->debit;
                    }
                    if ($journalItem->credit > 0) {
                        $new_balance = $old_balance + $journalItem->credit;
                    }
                    if (isset($new_balance)) {
                        $bankAccount->opening_balance = $new_balance;
                        $bankAccount->save();
                    }
                }
            }
            if ((float) $journalItem->debit > 0) {
                $data = [
                    'account_id' => $journalItem->account,
                    'transaction_type' => 'Debit',
                    'transaction_amount' => $journalItem->debit,
                    'reference' => 'Journal',
                    'reference_id' => $journalEntry->id,
                    'reference_sub_id' => $journalItem->id,
                    'date' => $journalEntry->date,
                ];
            } else {
                $data = [
                    'account_id' => $journalItem->account,
                    'transaction_type' => 'Credit',
                    'transaction_amount' => $journalItem->credit,
                    'reference' => 'Journal',
                    'reference_id' => $journalEntry->id,
                    'reference_sub_id' => $journalItem->id,
                    'date' => $journalEntry->date,
                ];
            }
            Utility::addTransactionLines($data, 'create');
        }

        $journalEntry->status = JournalEntry::STATUS_APPROVED;
        $journalEntry->save();

        return redirect()->route('journal-entry.index')->with('success', __('Journal entry approved successfully.'));
    }

    public function reject(JournalEntry $journalEntry)
    {
        if (! \Auth::user()->can('reject journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($journalEntry->created_by != \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($journalEntry->status != JournalEntry::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only pending journal entries can be rejected.'));
        }

        $journalEntry->status = JournalEntry::STATUS_REJECTED;
        $journalEntry->save();

        return redirect()->route('journal-entry.index')->with('success', __('Journal entry rejected successfully.'));
    }
}
