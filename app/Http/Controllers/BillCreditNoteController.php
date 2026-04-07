<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\DebitNote;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class BillCreditNoteController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        if(\Auth::user()->can('manage debit note'))
        {
            $bills = Bill::where('created_by', \Auth::user()->creatorId())->get();
            $standaloneCreditNotes = DebitNote::where('created_by', \Auth::user()->creatorId())->where('bill', 0)->where('is_credit', 1)->with('vendor')->get();

            return view('billCreditNote.index', compact('bills', 'standaloneCreditNotes'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function create($bill_id)
    {
        if(\Auth::user()->can('create debit note'))
        {

            $billDue = Bill::where('id', $bill_id)->first();

            return view('debitNote.create', compact('billDue', 'bill_id'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function store(Request $request, $bill_id)
    {

        if(\Auth::user()->can('create debit note'))
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
            $billDue = Bill::where('id', $bill_id)->first();

            if($request->amount > $billDue->getDue())
            {
                return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($billDue->getDue()) . ' credit limit of this bill.');
            }
            $bill               = Bill::where('id', $bill_id)->first();
            $debit              = new DebitNote();
            $debit->bill        = $bill_id;
            $debit->vendor      = $bill->vender_id;
            $debit->date        = $request->date;
            $debit->amount      = $request->amount;
            $debit->description = $request->description;
            $debit->save();

            Utility::updateUserBalance('vendor', $bill->vender_id, $request->amount, 'credit');


            return redirect()->back()->with('success', __('Debit Note successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function edit($bill_id, $debitNote_id)
    {
        if(\Auth::user()->can('edit debit note'))
        {

            $debitNote = DebitNote::find($debitNote_id);

            return view('debitNote.edit', compact('debitNote'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function update(Request $request, $bill_id, $debitNote_id)
    {

        if(\Auth::user()->can('edit debit note'))
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
            $debit = DebitNote::find($debitNote_id);
            if($debit->bill > 0)
            {
                $billDue = Bill::where('id', $debit->bill)->first();
                if($request->amount > $billDue->getDue())
                {
                    return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($billDue->getDue()) . ' credit limit of this bill.');
                }
                Utility::updateUserBalance('vendor', $billDue->vender_id, $debit->amount, 'debit');
                Utility::updateUserBalance('vendor', $billDue->vender_id, $request->amount, 'credit');
            }
            else
            {
                if($debit->vendor)
                {
                    Utility::updateUserBalance('vendor', $debit->vendor, $debit->amount, 'debit');
                    Utility::updateUserBalance('vendor', $debit->vendor, $request->amount, 'credit');
                }
            }

            $debit->date        = $request->date;
            $debit->amount      = $request->amount;
            $debit->description = $request->description;
            $debit->save();


            return redirect()->back()->with('success', __('Debit Note successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function destroy($bill_id, $debitNote_id)
    {
        if(\Auth::user()->can('delete debit note'))
        {
            $debitNote = DebitNote::find($debitNote_id);
            if($debitNote->vendor)
            {
                Utility::updateUserBalance('vendor', $debitNote->vendor, $debitNote->amount, 'debit');
            }
            $debitNote->delete();
            return redirect()->back()->with('success', __('Debit Note successfully deleted.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    
    public function getItems(Request $request)
    {
        $bill = Bill::with('items.product')->find($request->bill_id);
        if (!$bill) {
            return response()->json(['html' => '<div class="text-danger">Bill not found.</div>']);
        }
        $html = view('billCreditNote.invoice_items', compact('bill'))->render();
        return response()->json(['html' => $html]);
    }

    public function view($id)
    {
        try {
            $id = Crypt::decrypt($id);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        $note = DebitNote::with(['bill_data.vender_data', 'vendor'])->find($id);
        if($note->bill > 0)
        {
            $customer = $note->bill_data->vender_data;
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
            $iteams = $note->bill_data->items()->whereIn('product_id',$productIds)->get();
        }
        else
        {
            $customer = $note->vendor;
            $iteams = collect();
            $rdata = [];
        }
        return view('billCreditNote.view', compact('note','customer','iteams','rdata'));
    }

    public function customCreate()
    {
        if(\Auth::user()->can('create debit note'))
        {
            $bills = Bill::where('created_by', \Auth::user()->creatorId())->where('type','Bill')->get()->pluck('bill_id', 'id');
            $vendors = \App\Models\Vender::where('created_by', \Auth::user()->creatorId())->get()->pluck('name', 'vender_id');
            return view('billCreditNote.custom_create', compact('bills', 'vendors'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function customStore(Request $request)
    {
        if(\Auth::user()->can('create debit note'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'bill' => 'required|numeric',
                                   'amount' => 'required|numeric',
                                   'date' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $bill_id = $request->bill;
            $billDue = Bill::where('id', $bill_id)->first();
            $ex_cred = DebitNote::where(['created_by' =>  \Auth::user()->creatorId() , 'is_credit' => 1])->latest()->first();
            $uid = 1;
            if($ex_cred)
            {
               $uid =  $ex_cred->uid + 1;
            }
            if($request->amount > $billDue->getDue())
            {
                return redirect()->back()->with('error', 'Maximum ' . \Auth::user()->priceFormat($billDue->getDue()) . ' credit limit of this bill.');
            }
            $bill               = Bill::where('id', $bill_id)->first();
            $debit              = new DebitNote();
            $debit->uid     = $uid;
            $debit->bill        = $bill_id;
            $debit->vendor      = $bill->vender_id;
            $debit->date        = $request->date;
            $debit->amount      = $request->amount;
            $debit->description = $request->description;
            $debit->created_by = \Auth::user()->creatorId();
            $debit->data = $request->return_items ? json_encode($request->items) : null;
            $debit->is_credit = 1;
            $debit->save();
//            Utility::userBalance('vendor', $bill->vender_id, $request->amount, 'debit');
            Utility::updateUserBalance('vendor', $bill->vender_id, $request->amount, 'credit');
            
            if($request->return_items)
            {
                foreach($request->items as $pid => $qty)
                {
                    $qty = intval($qty);
                    if($qty > 0)
                    {
                        Utility::total_quantity('minus', $qty, $pid);
                    }
                }
            }


            return redirect()->back()->with('success', __('Debit Note successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function getbill(Request $request)
    {

        $bill = Bill::where('id', $request->bill_id)->first();
        echo json_encode($bill->getDue());
    }
}
