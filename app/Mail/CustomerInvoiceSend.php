<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Contract Management Phase 2 — customer invoice email.
 *
 * Written fresh on this branch: InvoiceController:1241 calls
 * `new CustomerInvoiceSend($invoice)` but the class did not exist
 * on main (it lived only on the unmerged branch
 * feat/invoice-send-portuguese) — the manual send path was broken.
 * This mailable fixes that path too, and the recurring engine
 * sends its invoices through it via LoggedMail.
 *
 * The PDF is generated once at issuance and stored; retries attach
 * the SAME stored bytes (AC08), so a retry can never send a
 * different PDF. $cc honours the contract's delivery_cc list.
 */
class CustomerInvoiceSend extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;
    public ?string $pdfBinary;
    public ?string $pdfFileName;

    /** @var string[] */
    public array $ccRecipients;

    /** True when this is a payment reminder (Phase 3): the subject and the letter change, the invoice and its PDF stay the same. */
    public bool $isReminder;

    public function __construct(Invoice $invoice, ?string $pdfBinary = null, ?string $pdfFileName = null, array $ccRecipients = [], bool $isReminder = false)
    {
        $this->invoice = $invoice;
        $this->pdfBinary = $pdfBinary;
        $this->pdfFileName = $pdfFileName;
        $this->ccRecipients = array_values(array_filter(array_map('trim', $ccRecipients)));
        $this->isReminder = $isReminder;
    }

    public function build()
    {
        $number = $this->invoice->invoice ?? ('#' . (string) $this->invoice->invoice_id);

        $subject = $this->isReminder
            ? __('Payment reminder') . ' — ' . __('Invoice') . ' ' . $number
            : __('Invoice') . ' ' . $number;

        $mail = $this->view('email.customer_invoice_send', ['isReminder' => $this->isReminder])
            ->subject($subject);

        if ($this->ccRecipients !== []) {
            $mail->cc($this->ccRecipients);
        }

        if ($this->pdfBinary !== null) {
            $mail->attachData(
                $this->pdfBinary,
                $this->pdfFileName ?? ('invoice-' . $this->invoice->invoice_id . '.pdf'),
                ['mime' => 'application/pdf']
            );
        }

        return $mail;
    }
}
