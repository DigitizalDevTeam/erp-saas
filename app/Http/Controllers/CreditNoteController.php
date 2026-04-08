<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Utility;
use App\Models\Customer;
use App\Models\InvoiceProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class CreditNoteController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {

        if(\Auth::user()->can('manage credit note'))
        {
            $invoices = Invoice::where('created_by', \Auth::user()->creatorId())->get();
            $standaloneCreditNotes = CreditNote::where('created_by', \Auth::user()->creatorId())->where('invoice', 0)->where('is_debit', 0)->with('customerRelation')->get();

            return view('creditNote.index', compact('invoices', 'standaloneCreditNotes'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    
    public function sale_debit_notes()
    {
        if(\Auth::user()->can('manage credit note'))
        {
            $invoices = Invoice::where('created_by', \Auth::user()->creatorId())->get();
            $standaloneDebitNotes = CreditNote::where('created_by', \Auth::user()->creatorId())->where('invoice', 0)->where('is_debit', 1)->with('customerRelation')->get();

            return view('creditNote.sale_debit_notes', compact('invoices', 'standaloneDebitNotes'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create($invoice_id)
    {

        if(\Auth::user()->can('create credit note'))
        {

            $invoiceDue = Invoice::where('id', $invoice_id)->first();

            return view('creditNote.create', compact('invoiceDue', 'invoice_id'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function store(Request $request, $invoice_id)
    {
        
        if(\Auth::user()->can('create credit note'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoiceDue = Invoice::where('id', $invoice_id)->first();
            if($request->amount > $invoiceDue->getDue())
            {
                return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
            }
            $invoice = Invoice::where('id', $invoice_id)->first();
            $ex_cred = CreditNote::where(['created_by' =>  \Auth::user()->creatorId() , 'is_debit' => 0])->latest()->first();
            $uid = 1;
            if($ex_cred)
            {
               $uid =  $ex_cred->uid + 1;
            }
            $credit              = new CreditNote();
            $credit->uid         = $uid;
            $credit->invoice     = $invoice_id;
            $credit->customer    = $invoice->customer_id;
            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->created_by = \Auth::user()->creatorId();
            $credit->save();

            $customer = Customer::find($invoice->customer_id);
            $balance = 0;
            if($customer->credit_balance != 0)
            {
                $balance = $customer->credit_balance + $request->amount;
            }

            $customer->credit_balance = $balance;
            $customer->save();

            Utility::updateUserBalance('customer', $invoice->customer_id, $request->amount, 'debit');

            return redirect()->back()->with('success', __('Credit Note successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function edit($invoice_id, $creditNote_id)
    {
        if(\Auth::user()->can('edit credit note'))
        {

            $creditNote = CreditNote::find($creditNote_id);

            return view('creditNote.edit', compact('creditNote'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function update(Request $request, $invoice_id, $creditNote_id)
    {

        if(\Auth::user()->can('edit credit note'))
        {

            $validator = \Validator::make(
                $request->all(), [
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $credit = CreditNote::find($creditNote_id);
            if($credit->invoice > 0)
            {
                $invoiceDue = Invoice::where('id', $credit->invoice)->first();
                if($request->amount > $invoiceDue->getDue()+$credit->amount)
                {
                    return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
                }
                Utility::updateUserBalance('customer', $invoiceDue->customer_id, $credit->amount, 'credit');
                Utility::updateUserBalance('customer', $invoiceDue->customer_id, $request->amount, 'debit');
            }
            else
            {
                if($credit->customer)
                {
                    Utility::updateUserBalance('customer', $credit->customer, $credit->amount, 'credit');
                    Utility::updateUserBalance('customer', $credit->customer, $request->amount, 'debit');
                }
            }

            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->save();


            return redirect()->back()->with('success', __('Credit Note successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function destroy($invoice_id, $creditNote_id)
    {
        if(\Auth::user()->can('delete credit note'))
        {

            $creditNote = CreditNote::find($creditNote_id);
            if($creditNote->customer)
            {
                Utility::updateUserBalance('customer', $creditNote->customer, $creditNote->amount, 'credit');
            }

            $creditNote->delete();

            return redirect()->back()->with('success', __('Credit Note successfully deleted.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function view($id)
    {
        try {
            $id = Crypt::decrypt($id);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        $note = CreditNote::with(['invoice_data.customer_data', 'customerRelation'])->find($id);
        if($note->invoice > 0)
        {
            $customer = $note->invoice_data->customer_data;
            $rdata = [];
            $productIds = [];
            if($note->data)
            {
                $data = json_decode($note->data);
                $rdata = $data;
                foreach ($data as $pid => $qty)
                {
                    $qty = intval($qty);
                    if($qty > 0)
                    {
                        $productIds[] = $pid; 
                    }
                }
            }
            $iteams = $note->invoice_data->items()->whereIn('product_id',$productIds)->get();
        }
        else
        {
            $customer = $note->customerRelation;
            $iteams = collect();
            $rdata = [];
        }
        return view('creditNote.view', compact('note','customer','iteams','rdata'));
    }
    public function debitView($id)
    {
        try {
            $id = Crypt::decrypt($id);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        $note = CreditNote::with(['invoice_data.customer_data', 'customerRelation'])->find($id);
        if($note->invoice > 0)
        {
            $customer = $note->invoice_data->customer_data;
            $rdata = [];
            $productIds = [];
            if($note->data)
            {
                $data = json_decode($note->data);
                $rdata = $data;
                foreach ($data as $pid => $qty)
                {
                    $qty = intval($qty);
                    if($qty > 0)
                    {
                        $productIds[] = $pid; 
                    }
                }
            }
            $iteams = $note->invoice_data->items()->whereIn('product_id',$productIds)->get();
        }
        else
        {
            $customer = $note->customerRelation;
            $iteams = collect();
            $rdata = [];
        }
        return view('creditNote.debit_view', compact('note','customer','iteams','rdata'));
    }

    public function customCreate()
    {
        if(\Auth::user()->can('create credit note'))
        {

            $invoices = Invoice::where('created_by', \Auth::user()->creatorId())->get()->pluck('invoice_id', 'id');
            $customers = \App\Models\Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            return view('creditNote.custom_create', compact('invoices', 'customers'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    
    public function getItems(Request $request)
    {
        $invoice = Invoice::with('items.product')->find($request->invoice_id);
        if (!$invoice) {
            return response()->json(['html' => '<div class="text-danger">Invoice not found.</div>']);
        }
        $html = view('creditNote.invoice_items', compact('invoice'))->render();
        return response()->json(['html' => $html]);
    }
    
    public function create_sale_debit_notes()
    {
        if(\Auth::user()->can('create credit note'))
        {

            $invoices = Invoice::where('created_by', \Auth::user()->creatorId())->get()->pluck('invoice_id', 'id');
            $customers = \App\Models\Customer::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'id');

            return view('creditNote.sale_debit_create', compact('invoices', 'customers'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function customStore(Request $request)
    {
        if(\Auth::user()->can('create credit note'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'invoice' => 'nullable|numeric',
                                   'customer' => 'nullable|required_without:invoice|numeric',
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoice_id = $request->invoice ?? 0;
            $customer_id = $request->customer ?? null;
            if($invoice_id > 0)
            {
                $invoiceDue = Invoice::where('id', $invoice_id)->first();
                if($request->amount > $invoiceDue->getDue())
                {
                    return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
                }
                $customer_id = $invoiceDue->customer_id;
            }
            
            $invoice             = $invoice_id > 0 ? Invoice::where('id', $invoice_id)->first() : null;
            $ex_cred = CreditNote::where(['created_by' =>  \Auth::user()->creatorId() , 'is_debit' => 0])->latest()->first();
            $uid = 1;
            if($ex_cred)
            {
               $uid =  $ex_cred->uid + 1;
            }
            $credit              = new CreditNote();
            $credit->uid     = $uid;
            $credit->invoice     = $invoice_id;
            $credit->customer    = $customer_id;
            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->created_by = \Auth::user()->creatorId();
            $credit->data = $request->return_items ? json_encode($request->items) : null;
            $credit->save();

            if($customer_id)
            {
                Utility::updateUserBalance('customer', $customer_id, $request->amount, 'debit');
            }
            
            if($request->return_items)
            {
                foreach($request->items as $pid => $qty)
                {
                    $qty = intval($qty);
                    if($qty > 0)
                    {
                        Utility::total_quantity('add', $qty, $pid);
                    }
                }
            }

            return redirect()->back()->with('success', __('Credit Note successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    
    
    public function save_sale_debit_notes(Request $request)
    {
        
        if(\Auth::user()->can('create credit note'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'invoice' => 'nullable|numeric',
                                   'customer' => 'nullable|required_without:invoice|numeric',
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoice_id = $request->invoice ?? 0;
            $customer_id = $request->customer ?? null;
            if($invoice_id > 0)
            {
                $invoiceDue = Invoice::where('id', $invoice_id)->first();
                if($request->amount > $invoiceDue->getDue())
                {
                    return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
                }
                $customer_id = $invoiceDue->customer_id;
            }
            $invoice             = $invoice_id > 0 ? Invoice::where('id', $invoice_id)->first() : null;
            $ex_cred = CreditNote::where(['created_by' =>  \Auth::user()->creatorId() , 'is_debit' => 1])->latest()->first();
            $uid = 1;
            if($ex_cred)
            {
               $uid =  $ex_cred->uid + 1;
            }
            $credit              = new CreditNote();
            $credit->uid     = $uid;
            $credit->invoice     = $invoice_id;
            $credit->customer    = $customer_id;
            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->created_by = \Auth::user()->creatorId();
            $credit->is_debit = 1;
            $credit->save();

            if($customer_id)
            {
                Utility::updateUserBalance('customer', $customer_id, $request->amount, 'credit');
            }

            return redirect()->back()->with('success', __('Debit Note successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getinvoice(Request $request)
    {
        $invoice = Invoice::where('id', $request->id)->first();

        echo json_encode($invoice->getDue());
    }

}
