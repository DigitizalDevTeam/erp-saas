<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Customer;
use App\Models\ProductService;
use App\Models\Vender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditDebitNoteReportsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function creditNoteReport(Request $request)
    {
        if (!\Auth::user()->can('menu.dashboard.accounting.reports.management.credit_note')
            && !\Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $customers = Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');
        $products = ProductService::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

        $query = CreditNote::with(['customerRelation', 'invoice_data', 'invoice_data.items.product'])
            ->where('created_by', \Auth::user()->creatorId());

        // Filters
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        if ($request->filled('customer')) {
            $query->where('customer', $request->customer);
        }

        if ($request->filled('product')) {
            $query->whereHas('invoice_data.items', function ($q) use ($request) {
                $q->where('product_id', $request->product);
            });
        }

        $creditNotes = $query->get();

        return view('reports.credit_note_report', compact('creditNotes', 'customers', 'products', 'request'));
    }

    public function debitNoteReport(Request $request)
    {
        if (!\Auth::user()->can('menu.dashboard.accounting.reports.management.debit_note')
            && !\Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $customers = Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

        $query = CreditNote::with(['customerRelation', 'invoice_data'])
            ->where('created_by', \Auth::user()->creatorId())
            ->where('is_debit', 1);

        // Filters
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        if ($request->filled('customer')) {
            $query->where('customer', $request->customer);
        }

        $debitNotes = $query->get();

        return view('reports.debit_note_report', compact('debitNotes', 'customers', 'request'));
    }
}