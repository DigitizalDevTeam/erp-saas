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

            return view('creditNote.index', compact('invoices'));
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

            return view('creditNote.sale_debit_notes', compact('invoices'));
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

            $invoiceDue = Invoice::where('id', $invoice_id)->first();
            $credit = CreditNote::find($creditNote_id);
            if($request->amount > $invoiceDue->getDue()+$credit->amount)
            {
                return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
            }


            Utility::updateUserBalance('customer', $invoiceDue->customer_id, $credit->amount, 'credit');

            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->save();

            Utility::updateUserBalance('customer', $invoiceDue->customer_id, $request->amount, 'debit');


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
            $creditNote->delete();

            Utility::updateUserBalance('customer', $creditNote->customer, $creditNote->amount, 'credit');

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
        $note = CreditNote::with('invoice_data.customer_data')->find($id);
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
        return view('creditNote.view', compact('note','customer','iteams','rdata'));
    }
    public function debitView($id)
    {
        try {
            $id = Crypt::decrypt($id);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        $note = CreditNote::with('invoice_data.customer_data')->find($id);
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
        return view('creditNote.debit_view', compact('note','customer','iteams','rdata'));
    }

    public function customCreate()
    {
        if(\Auth::user()->can('create credit note'))
        {

            $invoices = Invoice::where('created_by', \Auth::user()->creatorId())->get()->pluck('invoice_id', 'id');

            return view('creditNote.custom_create', compact('invoices'));
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

            return view('creditNote.sale_debit_create', compact('invoices'));
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
                                   'invoice' => 'required|numeric',
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoice_id = $request->invoice;
            $invoiceDue = Invoice::where('id', $invoice_id)->first();

            if($request->amount > $invoiceDue->getDue())
            {
                return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
            }
            
            $invoice             = Invoice::where('id', $invoice_id)->first();
            $ex_cred = CreditNote::where(['created_by' =>  \Auth::user()->creatorId() , 'is_debit' => 0])->latest()->first();
            $uid = 1;
            if($ex_cred)
            {
               $uid =  $ex_cred->uid + 1;
            }
            $credit              = new CreditNote();
            $credit->uid     = $uid;
            $credit->invoice     = $invoice_id;
            $credit->customer    = $invoice->customer_id;
            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->created_by = \Auth::user()->creatorId();
            $credit->data = $request->return_items ? json_encode($request->items) : null;
            $credit->save();

            Utility::updateUserBalance('customer', $invoice->customer_id, $request->amount, 'debit');
            
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
                                   'invoice' => 'required|numeric',
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoice_id = $request->invoice;
            $invoiceDue = Invoice::where('id', $invoice_id)->first();

            if($request->amount > $invoiceDue->getDue())
            {
                return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($invoiceDue->getDue()) . ' credit limit of this invoice.');
            }
            $invoice             = Invoice::where('id', $invoice_id)->first();
            $ex_cred = CreditNote::where(['created_by' =>  \Auth::user()->creatorId() , 'is_debit' => 1])->latest()->first();
            $uid = 1;
            if($ex_cred)
            {
               $uid =  $ex_cred->uid + 1;
            }
            $credit              = new CreditNote();
            $credit->uid     = $uid;
            $credit->invoice     = $invoice_id;
            $credit->customer    = $invoice->customer_id;
            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->created_by = \Auth::user()->creatorId();
            $credit->is_debit = 1;
            $credit->save();

            Utility::updateUserBalance('customer', $invoice->customer_id, $request->amount, 'credit');

            return redirect()->back()->with('success', __('Credit Note successfully created.'));
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
