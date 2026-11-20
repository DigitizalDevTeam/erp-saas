<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\CreditNote;
use App\Models\CreditNoteRefund;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\TreasuryCashSession;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryPettyCashFund;
use App\Models\Utility;
use App\Services\CreditNoteRefundService;
use App\Services\CustomerCreditService;
use App\Services\TreasurySettings;
use Barryvdh\DomPDF\Facade\Pdf;
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

    // ------------------------------------------------------------------
    // Credit Notes Phase 2 — approval (client answer Q1) + refund
    // (Reembolso: bank transfer / cash from petty cash or till).
    // ------------------------------------------------------------------

    /**
     * Approve a pending note. Mirrors JournalEntryController::approve:
     * permission + company + pending-only. Approval is only needed
     * while the per-company switch is on; notes created approved
     * never reach this path.
     */
    public function approve($invoice_id, $creditNote_id)
    {
        if (! \Auth::user()->can('approve credit note')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $note = CreditNote::find($creditNote_id);
        if (! $note || (int) $note->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        if ((int) ($note->status ?? CreditNote::STATUS_APPROVED) !== CreditNote::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only a pending credit note can be approved.'));
        }

        $note->status = CreditNote::STATUS_APPROVED;
        $note->approved_by = \Auth::user()->id;
        $note->approved_at = now();
        $note->save();

        // Phase 3: a note kept as customer credit only becomes
        // spendable credit once it is approved — the lot is created
        // here for notes that were pending, and at creation for
        // notes issued already approved.
        if ($note->disposition === self::DISPOSITION_CREDIT) {
            app(CustomerCreditService::class)->addCredit(
                (int) \Auth::user()->creatorId(),
                $note,
                (int) \Auth::user()->id
            );
        }

        return redirect()->back()->with('success', __('Credit Note successfully approved.'));
    }

    /** Reject a pending note (same gate as approve). */
    public function reject($invoice_id, $creditNote_id)
    {
        if (! \Auth::user()->can('approve credit note')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $note = CreditNote::find($creditNote_id);
        if (! $note || (int) $note->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }
        if ((int) ($note->status ?? CreditNote::STATUS_APPROVED) !== CreditNote::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Only a pending credit note can be rejected.'));
        }

        $note->status = CreditNote::STATUS_REJECTED;
        $note->approved_by = \Auth::user()->id;
        $note->approved_at = now();
        $note->save();

        return redirect()->back()->with('success', __('Credit Note successfully rejected.'));
    }

    /** Refund form: choose bank transfer or cash (petty cash / till). */
    public function refundForm($invoice_id, $creditNote_id)
    {
        if (! \Auth::user()->can('manage credit note refund')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $note = $this->refundableNote($creditNote_id);
        if (! $note) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }

        $creatorId = \Auth::user()->creatorId();
        $bankAccounts = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->where('type', TreasuryFinancialAccount::TYPE_BANK)
            ->orderBy('code')
            ->get();
        $cashAccounts = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', TreasuryFinancialAccount::STATUS_ACTIVE)
            ->where('type', TreasuryFinancialAccount::TYPE_CASH_REGISTER)
            ->orderBy('code')
            ->get();
        $pettyFunds = TreasuryPettyCashFund::where('created_by', $creatorId)
            ->where('status', TreasuryPettyCashFund::STATUS_ACTIVE)
            ->with('financialAccount')
            ->orderBy('id')
            ->get();
        $openSessions = TreasuryCashSession::where('created_by', $creatorId)
            ->where('state', TreasuryCashSession::STATE_OPEN)
            ->with('register')
            ->orderBy('opened_at', 'desc')
            ->get();
        $existingRefund = CreditNoteRefund::where('credit_note_id', $note->id)->first();

        return view('creditNote.refund', compact(
            'note', 'invoice_id', 'bankAccounts', 'cashAccounts',
            'pettyFunds', 'openSessions', 'existingRefund'
        ));
    }

    /** Pay the refund (full note amount, once). */
    public function refundStore(Request $request, $invoice_id, $creditNote_id)
    {
        if (! \Auth::user()->can('manage credit note refund')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $note = $this->refundableNote($creditNote_id);
        if (! $note) {
            return redirect()->back()->with('error', __('Note Not Found.'));
        }

        $validator = \Validator::make($request->all(), [
            'method' => 'required|in:transfer,cash',
            'financial_account_id' => 'nullable|integer',
            'petty_cash_fund_id' => 'nullable|integer',
            'cash_session_id' => 'nullable|integer',
            'transfer_receipt' => 'nullable|file|max:10240',
            'signed_receipt' => 'nullable|file|max:10240',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->messages()->first());
        }

        try {
            $refund = (new CreditNoteRefundService())->refundNote(
                \Auth::user()->creatorId(),
                (int) $note->id,
                \Auth::user()->id,
                [
                    'method' => $request->method,
                    'financial_account_id' => $request->financial_account_id,
                    'petty_cash_fund_id' => $request->petty_cash_fund_id,
                    'cash_session_id' => $request->cash_session_id,
                    'transfer_receipt' => $this->storeRefundAttachment($request, 'transfer_receipt'),
                    'signed_receipt' => $this->storeRefundAttachment($request, 'signed_receipt'),
                ]
            );
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('note.view', \Crypt::encrypt($note->id))->with(
            'success',
            __('Refund paid. Reference') . ' ' . $refund->reference . '.'
        );
    }

    /**
     * Numbered printable internal receipt for a refund (the slip the
     * client asked for: bank transfer keeps the transfer receipt,
     * cash keeps the signed customer receipt — both are listed on
     * this slip). Available for till and back-office refunds alike.
     */
    public function refundSlip($refund_id)
    {
        if (! \Auth::user()->can('manage credit note refund')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $refund = (new CreditNoteRefundService())->refundForCompany(\Auth::user()->creatorId(), (int) $refund_id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $refund->load(['note', 'customer', 'invoice', 'financialAccount', 'pettyCashFund', 'cashSession', 'operation']);

        return Pdf::loadView('creditNote.refund-slip', compact('refund'))
            ->download('refund-slip-' . $refund->reference . '.pdf');
    }

    /**
     * The note a refund can be paid on: ours, a credit note (not a
     * debit note) with disposition 'refund'. Status, single-refund
     * and source rules are enforced by CreditNoteRefundService.
     */
    protected function refundableNote($creditNote_id): ?CreditNote
    {
        $note = CreditNote::find($creditNote_id);
        if (! $note || (int) $note->created_by !== (int) \Auth::user()->creatorId()) {
            return null;
        }
        if ((int) $note->is_debit === 1 || (string) ($note->disposition ?? '') !== 'refund') {
            return null;
        }

        return $note;
    }

    /**
     * Store a refund receipt file (transfer receipt / signed customer
     * receipt) under uploads/credit-note-refunds, same storage-limit
     * pattern as the note attachment.
     */
    protected function storeRefundAttachment(Request $request, string $field): ?string
    {
        if (empty($request->$field) || ! $request->hasFile($field)) {
            return null;
        }

        $image_size = $request->file($field)->getSize();
        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
        if ($result != 1) {
            return null;
        }

        $fileName = time() . '_' . $request->file($field)->getClientOriginalName();
        $request->file($field)->storeAs('uploads/credit-note-refunds', $fileName);

        return $fileName;
    }

    public function index()
    {

        if(\Auth::user()->can('manage credit note'))
        {
            $invoices = Invoice::where('created_by', \Auth::user()->creatorId())->get();
            $standaloneCreditNotes = CreditNote::where('created_by', \Auth::user()->creatorId())->where('invoice', 0)->where('is_debit', 0)->with('customerRelation')->get();

            // Phase 3: per-note refund + credit-use, for the list
            // columns. One refund max per note (Phase 2 rule).
            $refundsByNote = CreditNoteRefund::where('created_by', \Auth::user()->creatorId())
                ->get()
                ->keyBy('credit_note_id');
            $creditUsedByNote = CustomerCreditLedger::where('created_by', \Auth::user()->creatorId())
                ->where('type', CustomerCreditLedger::TYPE_USED)
                ->selectRaw('credit_note_id, SUM(amount * -1) as used')
                ->groupBy('credit_note_id')
                ->pluck('used', 'credit_note_id');

            return view('creditNote.index', compact('invoices', 'standaloneCreditNotes', 'refundsByNote', 'creditUsedByNote'));
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
            if(round((float) $request->amount, 2) > round($maxCredit, 2))
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
            // Phase 2 (client answer Q1): when the approval switch is
            // on, the note is created Pending and needs approval
            // before any refund can be paid; otherwise it is issued
            // approved, as before.
            $credit->status = TreasurySettings::creditNoteApprovalRequired(\Auth::user()->creatorId())
                ? CreditNote::STATUS_PENDING
                : CreditNote::STATUS_APPROVED;
            $credit->created_by = \Auth::user()->creatorId();
            $credit->save();

            // "Keep as customer credit" adds to the customer's credit
            // balance — always, including the first note (the old code
            // only added when a balance already existed, so the first
            // note was lost). "Refund" adds nothing: the money is
            // paid out instead (Phase 2). Phase 3: the credit only
            // becomes a spendable lot when the note is approved —
            // a pending note adds nothing until approve() creates it.
            if($request->disposition === self::DISPOSITION_CREDIT
                && (int) $credit->status === CreditNote::STATUS_APPROVED)
            {
                app(CustomerCreditService::class)->addCredit(
                    (int) \Auth::user()->creatorId(),
                    $credit,
                    (int) \Auth::user()->id
                );
            }

            Utility::updateUserBalance('customer', $invoice->customer_id, $request->amount, 'debit');

            return redirect()->back()->with(
                'success',
                $credit->status === CreditNote::STATUS_PENDING
                    ? __('Credit Note successfully created. It is pending approval.')
                    : __('Credit Note successfully created.')
            );
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
                if(!$isDebit && round((float) $request->amount, 2) > round($this->maxCreditNoteAmount($invoiceDue)+$credit->amount, 2))
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

            $newAttachment = $this->storeCreditNoteAttachment($request);
            if($newAttachment)
            {
                $credit->attachment = $newAttachment;
            }

            $credit->date        = $request->date;
            $credit->amount      = $request->amount;
            $credit->description = $request->description;
            $credit->save();

            // Phase 3: move the note's credit lot by the amount change
            // (remaining clamped at 0 — credit already used stays
            // used). Refund notes and notes without a disposition
            // never touched the credit pool, so they stay out. The
            // disposition itself is set at creation, not changed here.
            if(! $isDebit && $credit->disposition === self::DISPOSITION_CREDIT && $credit->customer)
            {
                app(CustomerCreditService::class)->adjustLotForUpdatedNote($credit, (float) $oldAmount);
            }


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

            // Removing a note kept as customer credit takes only its
            // unconsumed remainder back out of the credit pool
            // (Phase 3 lot) — credit already used on an invoice stays
            // used. Refund notes and notes without a disposition
            // never touched it, so deleting them leaves it alone.
            if(! $isDebit && $creditNote->disposition === self::DISPOSITION_CREDIT && $creditNote->customer)
            {
                app(CustomerCreditService::class)->reverseLotForDestroyedNote($creditNote);
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
        // Phase 2: the refund (if paid) for the status badge + buttons.
        $refund = CreditNoteRefund::where('credit_note_id', $note->id)->first();
        $approvalRequired = TreasurySettings::creditNoteApprovalRequired(\Auth::user()->creatorId());

        return view('creditNote.view', compact('note','customer','iteams','rdata','refund','approvalRequired'));
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
                if(round((float) $request->amount, 2) > round($maxCredit, 2))
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
            // Phase 2 (client answer Q1): see store().
            $credit->status = TreasurySettings::creditNoteApprovalRequired(\Auth::user()->creatorId())
                ? CreditNote::STATUS_PENDING
                : CreditNote::STATUS_APPROVED;
            $credit->is_debit = 0;
            $credit->data = $request->return_items ? json_encode($request->items ?? []) : null;
            $credit->save();

            if($customer_id)
            {
                Utility::updateUserBalance('customer', $customer_id, $request->amount, 'debit');

                // "Keep as customer credit" adds to the credit balance
                // — always, including the first note. "Refund" adds
                // nothing: the money is paid out instead (Phase 2).
                // Phase 3: the credit only becomes a spendable lot
                // when the note is approved — a pending note adds
                // nothing until approve() creates it.
                if($request->disposition === self::DISPOSITION_CREDIT
                    && (int) $credit->status === CreditNote::STATUS_APPROVED)
                {
                    app(CustomerCreditService::class)->addCredit(
                        (int) \Auth::user()->creatorId(),
                        $credit,
                        (int) \Auth::user()->id
                    );
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

            return redirect()->back()->with(
                'success',
                $credit->status === CreditNote::STATUS_PENDING
                    ? __('Credit Note successfully created. It is pending approval.')
                    : __('Credit Note successfully created.')
            );
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
