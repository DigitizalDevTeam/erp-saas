<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Utility;
use App\Models\Customer;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class CreditNoteController extends Controller
{
    /**
     * Credit Notes Phase 1 — what happens to the money on a credit note.
     * 'refund': the money is paid back (Treasury Reembolso, Phase 2);
     * the note does NOT add to the customer's credit_balance.
     * 'credit': the money stays as customer credit; the note adds to
     * the customer's credit_balance.
     */
    public const DISPOSITION_REFUND = 'refund';
    public const DISPOSITION_CREDIT = 'credit';

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Largest credit note this invoice can still take.
     *
     * A note can settle what is still owed (getDue) and return money
     * already paid, so the cap is getDue() + amount paid. Existing
     * credit notes are already subtracted inside getDue(), so the cap
     * is the invoice total + debit notes - notes already issued:
     * - unpaid invoice: paid is 0, cap is getDue() (unchanged).
     * - paid invoice: getDue() is 0, cap is paid - notes issued.
     * - partly paid: cap covers the remaining due plus the paid part.
     * Cancelled / draft invoices can take no note at all.
     */
    protected function maxCreditNoteAmount(Invoice $invoice): float
    {
        if (in_array((int) $invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_DRAFT], true)) {
            return 0;
        }

        $paid = (float) $invoice->payments->sum('amount');

        return max(0, (float) $invoice->getDue() + $paid);
    }

    /**
     * Store an optional supporting document uploaded with a credit
     * note. Same pattern as invoice payment receipts: storage-limit
     * check, then storeAs('uploads/credit-notes', ...). Returns the
     * stored file name, or null when no file was uploaded (or the
     * storage limit blocks it).
     */
    protected function storeCreditNoteAttachment(Request $request): ?string
    {
        if (empty($request->attachment) || ! $request->hasFile('attachment')) {
            return null;
        }

        $image_size = $request->file('attachment')->getSize();
        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
        if ($result != 1) {
            return null;
        }

        $fileName = time() . '_' . $request->file('attachment')->getClientOriginalName();
        $request->file('attachment')->storeAs('uploads/credit-notes', $fileName);

        return $fileName;
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
            $paidAmount = $invoiceDue ? (float) $invoiceDue->payments->sum('amount') : 0;
            $maxCreditAmount = $invoiceDue ? $this->maxCreditNoteAmount($invoiceDue) : 0;

            return view('creditNote.create', compact('invoiceDue', 'invoice_id', 'paidAmount', 'maxCreditAmount'));
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
                                   'description' => 'required',
                                   'disposition' => 'required|in:refund,credit',
                                   'attachment' => 'nullable|file|max:10240',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoiceDue = Invoice::where('id', $invoice_id)->first();
            $invoiceError = $this->validateInvoiceForNote($invoiceDue, true);
            if ($invoiceError) {
                return redirect()->back()->with('error', $invoiceError);
            }
            $maxCredit = $this->maxCreditNoteAmount($invoiceDue);
            if($request->amount > $maxCredit)
            {
                return redirect()->back()->with('error', __('Maximum :amount credit limit of this invoice.', ['amount' => \Auth::user()->priceFormat($maxCredit)]));
            }
            // A refund returns money, so it needs a paid amount to
            // return. "Keep as customer credit" settles the debt and
            // needs no payment.
            $paidAmount = (float) $invoiceDue->payments->sum('amount');
            if($request->disposition === self::DISPOSITION_REFUND && $paidAmount <= 0)
            {
                return redirect()->back()->with('error', __('A refund requires a paid amount on this invoice.'));
            }
            $invoice = $invoiceDue;
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
            $credit->disposition = $request->disposition;
            $credit->attachment  = $this->storeCreditNoteAttachment($request);
            $credit->created_by = \Auth::user()->creatorId();
            $credit->save();

            // "Keep as customer credit" adds to the customer's credit
            // balance — always, including the first note (the old code
            // only added when a balance already existed, so the first
            // note was lost). "Refund" adds nothing: the money is
            // paid out instead (Phase 2).
            if($request->disposition === self::DISPOSITION_CREDIT)
            {
                $customer = Customer::find($invoice->customer_id);
                if($customer)
                {
                    $customer->credit_balance = ($customer->credit_balance ?? 0) + $request->amount;
                    $customer->save();
                }
            }

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

            $credit = CreditNote::find($creditNote_id);
            if(!$credit)
            {
                return redirect()->back()->with('error', __('Note not found.'));
            }

            $isDebit = (int) $credit->is_debit === 1;

            // The reason is mandatory for credit notes (Phase 1);
            // debit notes keep the old optional description.
            $rules = [
                'amount' => 'required|numeric',
                'date' => 'required',
                'attachment' => 'nullable|file|max:10240',
            ];
            if(! $isDebit)
            {
                $rules['description'] = 'required';
            }
            $validator = \Validator::make($request->all(), $rules);

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $oldAmount = (float) $credit->amount;

            if($credit->invoice > 0)
            {
                $invoiceDue = Invoice::where('id', $credit->invoice)->first();
                if(!$isDebit && $request->amount > $this->maxCreditNoteAmount($invoiceDue)+$credit->amount)
                {
                    return redirect()->back()->with('error', __('Maximum :amount credit limit of this invoice.', ['amount' => \Auth::user()->priceFormat($this->maxCreditNoteAmount($invoiceDue))]));
                }
                // Reverse previous balance effect, then apply new amount.
                if($isDebit)
                {
                    Utility::updateUserBalance('customer', $invoiceDue->customer_id, $credit->amount, 'debit');
                    Utility::updateUserBalance('customer', $invoiceDue->customer_id, $request->amount, 'credit');
                }
                else
                {
                    Utility::updateUserBalance('customer', $invoiceDue->customer_id, $credit->amount, 'credit');
                    Utility::updateUserBalance('customer', $invoiceDue->customer_id, $request->amount, 'debit');
                }
            }
            else
            {
                if($credit->customer)
                {
                    if($isDebit)
                    {
                        Utility::updateUserBalance('customer', $credit->customer, $credit->amount, 'debit');
                        Utility::updateUserBalance('customer', $credit->customer, $request->amount, 'credit');
                    }
                    else
                    {
                        Utility::updateUserBalance('customer', $credit->customer, $credit->amount, 'credit');
                        Utility::updateUserBalance('customer', $credit->customer, $request->amount, 'debit');
                    }
                }
            }

            // Keep the customer's credit balance in step: a note kept
            // as customer credit moves the balance by the amount
            // change; refund notes and notes without a disposition
            // (legacy / void notes) never touch it. The disposition
            // itself is set at creation and is not changed here.
            if(! $isDebit && $credit->disposition === self::DISPOSITION_CREDIT && $credit->customer)
            {
                $customer = Customer::find($credit->customer);
                if($customer)
                {
                    $customer->credit_balance = ($customer->credit_balance ?? 0) + ((float) $request->amount - $oldAmount);
                    $customer->save();
                }
            }

            $newAttachment = $this->storeCreditNoteAttachment($request);
            if($newAttachment)
            {
                $credit->attachment = $newAttachment;
            }

            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->save();


            return redirect()->back()->with('success', $isDebit ? __('Debit Note successfully updated.') : __('Credit Note successfully updated.'));
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
            if(!$creditNote)
            {
                return redirect()->back()->with('error', __('Note not found.'));
            }

            $isDebit = (int) $creditNote->is_debit === 1;

            if($creditNote->customer)
            {
                // Credit note creation debited customer; debit note creation credited customer.
                Utility::updateUserBalance('customer', $creditNote->customer, $creditNote->amount, $isDebit ? 'debit' : 'credit');
            }

            // Removing a note kept as customer credit takes the amount
            // back out of the credit balance. Refund notes and notes
            // without a disposition (legacy / void notes) never
            // touched it, so deleting them leaves it alone.
            if(! $isDebit && $creditNote->disposition === self::DISPOSITION_CREDIT && $creditNote->customer)
            {
                $customer = Customer::find($creditNote->customer);
                if($customer)
                {
                    $customer->credit_balance = ($customer->credit_balance ?? 0) - $creditNote->amount;
                    $customer->save();
                }
            }

            $creditNote->delete();

            return redirect()->back()->with('success', $isDebit ? __('Debit Note successfully deleted.') : __('Credit Note successfully deleted.'));

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
        if (! $note) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        if ((int) $note->is_debit === 1) {
            return redirect()->route('debit.note.view', Crypt::encrypt($note->id));
        }
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
        if (! $note) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        if ((int) $note->is_debit !== 1) {
            return redirect()->route('note.view', Crypt::encrypt($note->id));
        }
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

    /**
     * Invoices eligible for credit/debit notes (exclude cancelled,
     * draft — and paid, except for credit notes since Phase 1: a paid
     * invoice can take a credit note up to what was actually paid).
     * Debit notes must never attach to paid invoices.
     */
    protected function eligibleInvoicesForNotes(bool $includePaid = false)
    {
        $excluded = [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_CANCELLED,
        ];
        if (! $includePaid) {
            $excluded[] = Invoice::STATUS_PAID;
        }

        return Invoice::where('created_by', \Auth::user()->creatorId())
            ->whereNotIn('status', $excluded)
            ->get()
            ->pluck('invoice_id', 'id');
    }

    protected function productsForNotes()
    {
        return ProductService::where('created_by', \Auth::user()->creatorId())
            ->orderBy('name')
            ->get(['id', 'name', 'sale_price']);
    }

    /**
     * @return string|null error message when invoice cannot be used
     */
    protected function validateInvoiceForNote(?Invoice $invoice, bool $isCreditNote = true): ?string
    {
        if (!$invoice) {
            return __('Invoice not found.');
        }

        if ((int) $invoice->status === Invoice::STATUS_CANCELLED) {
            return __('Cannot create note for a cancelled invoice.');
        }

        if ((int) $invoice->status === Invoice::STATUS_DRAFT) {
            return __('Cannot create note for a draft invoice.');
        }

        // Phase 1: a PAID invoice can take a credit note (capped at
        // what was actually paid). Debit notes still never attach to
        // a paid invoice.
        if ((int) $invoice->status === Invoice::STATUS_PAID && ! $isCreditNote) {
            return __('Cannot create note for a paid invoice.');
        }

        if ($isCreditNote && $this->maxCreditNoteAmount($invoice) <= 0) {
            return __('This invoice has no remaining amount for a credit note.');
        }

        return null;
    }

    public function customCreate()
    {
        if(\Auth::user()->can('create credit note'))
        {
            $invoices = $this->eligibleInvoicesForNotes(true);
            $customers = Customer::forDocuments()->get()->pluck('name', 'id');
            $products = $this->productsForNotes();
            // Amount already paid per listed invoice, so the form
            // only offers "Refund the money" for invoices with money
            // to return.
            $invoicePaidAmounts = InvoicePayment::whereIn('invoice_id', array_keys($invoices->toArray()))
                ->selectRaw('invoice_id, SUM(amount) as paid')
                ->groupBy('invoice_id')
                ->pluck('paid', 'invoice_id')
                ->all();

            return view('creditNote.custom_create', compact('invoices', 'customers', 'products', 'invoicePaidAmounts'));
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
            return response()->json(['html' => '<div class="text-danger">'.e(__('Invoice not found.')).'</div>']);
        }
        // The debit-note form passes note_type=debit; credit-note
        // forms pass nothing. Paid invoices may load items for a
        // credit note (Phase 1) but never for a debit note.
        $isDebit = $request->note_type === 'debit';
        $invoiceError = $this->validateInvoiceForNote($invoice, ! $isDebit);
        if ($invoiceError) {
            return response()->json(['html' => '<div class="text-danger">'.e($invoiceError).'</div>']);
        }
        $html = view('creditNote.invoice_items', compact('invoice'))->render();
        return response()->json(['html' => $html]);
    }
    
    public function create_sale_debit_notes()
    {
        if(\Auth::user()->can('create credit note'))
        {
            $invoices = $this->eligibleInvoicesForNotes();
            $customers = Customer::forDocuments()->get()->pluck('name', 'id');
            $products = $this->productsForNotes();

            return view('creditNote.sale_debit_create', compact('invoices', 'customers', 'products'));
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
                                   'amount' => 'required|numeric|gt:0',
                                   'date' => 'required',
                                   'description' => 'required',
                                   'disposition' => 'required|in:refund,credit',
                                   'attachment' => 'nullable|file|max:10240',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $invoice_id = $request->invoice ?? 0;
            $customer_id = $request->customer ?? null;
            $paidAmount = 0;
            if($invoice_id > 0)
            {
                $invoiceDue = Invoice::where('id', $invoice_id)->first();
                $invoiceError = $this->validateInvoiceForNote($invoiceDue, true);
                if ($invoiceError) {
                    return redirect()->back()->with('error', $invoiceError);
                }
                $maxCredit = $this->maxCreditNoteAmount($invoiceDue);
                if($request->amount > $maxCredit)
                {
                    return redirect()->back()->with('error', __('Maximum :amount credit limit of this invoice.', ['amount' => \Auth::user()->priceFormat($maxCredit)]));
                }
                $paidAmount = (float) $invoiceDue->payments->sum('amount');
                $customer_id = $invoiceDue->customer_id;
            }
            // A refund returns money, so it needs a paid amount to
            // return — including standalone notes, which have none.
            if($request->disposition === self::DISPOSITION_REFUND && $paidAmount <= 0)
            {
                return redirect()->back()->with('error', __('A refund requires a paid amount on this invoice.'));
            }
            
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
            $credit->disposition = $request->disposition;
            $credit->attachment  = $this->storeCreditNoteAttachment($request);
            $credit->created_by = \Auth::user()->creatorId();
            $credit->is_debit = 0;
            $credit->data = $request->return_items ? json_encode($request->items ?? []) : null;
            $credit->save();

            if($customer_id)
            {
                Utility::updateUserBalance('customer', $customer_id, $request->amount, 'debit');

                // "Keep as customer credit" adds to the credit balance
                // — always, including the first note. "Refund" adds
                // nothing: the money is paid out instead (Phase 2).
                if($request->disposition === self::DISPOSITION_CREDIT)
                {
                    $customer = Customer::find($customer_id);
                    if($customer)
                    {
                        $customer->credit_balance = ($customer->credit_balance ?? 0) + $request->amount;
                        $customer->save();
                    }
                }
            }
            
            if($request->return_items && is_array($request->items))
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
                                   'amount' => 'required|numeric|gt:0',
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
                // Debit notes must never attach to paid/cancelled invoices (critical).
                $invoiceError = $this->validateInvoiceForNote($invoiceDue, false);
                if ($invoiceError) {
                    return redirect()->back()->with('error', $invoiceError);
                }
                $customer_id = $invoiceDue->customer_id;
            }
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
            $credit->data = $request->return_items ? json_encode($request->items ?? []) : null;
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
