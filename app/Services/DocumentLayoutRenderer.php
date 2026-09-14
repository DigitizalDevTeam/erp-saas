<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\DocumentLayout;
use App\Models\Invoice;
use App\Models\Pos;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Utility;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class DocumentLayoutRenderer
{
    public static function activeLayout(string $documentType, int $createdBy): ?DocumentLayout
    {
        return DocumentLayout::activeFor($documentType, $createdBy);
    }

    /**
     * @param  Invoice|Bill|Purchase|Quotation|Pos  $document
     * @return \Illuminate\Contracts\View\View|null
     */
    public static function tryRender(string $documentType, $document, array $extra = [])
    {
        $createdBy = (int) ($document->created_by ?? 0);
        if ($createdBy <= 0) {
            return null;
        }

        $layout = self::activeLayout($documentType, $createdBy);
        if (! $layout) {
            return null;
        }

        $payload = self::buildPayload($documentType, $document, $extra);

        return view('document_layouts.render', [
            'layout' => $layout,
            'documentType' => $documentType,
            'payload' => $payload,
            'preview' => (bool) ($extra['preview'] ?? false),
        ]);
    }

    /**
     * @param  Invoice|Bill|Purchase|Quotation|Pos  $document
     */
    public static function buildPayload(string $documentType, $document, array $extra = []): array
    {
        $settings = $extra['settings'] ?? Utility::settingsById($document->created_by);
        $color = $extra['color'] ?? ('#' . ($settings[$documentType . '_color'] ?? $settings['invoice_color'] ?? '6fd943'));
        $img = $extra['img'] ?? self::defaultLogo($settings, $documentType);
        $fontColor = $extra['font_color'] ?? Utility::getFontColor($color);

        $party = $extra['party'] ?? null;
        $partyLabel = __('Bill To');
        $number = '';
        $date = '';
        $dueDate = '';
        $items = [];
        $subTotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;
        $notes = '';
        $qrHtml = '';
        $status = '';

        switch ($documentType) {
            case 'invoice':
                /** @var Invoice $document */
                $party = $party ?? $document->customer;
                $partyLabel = __('Bill To');
                $number = $document->invoice_id ?? $document->id;
                $date = $document->issue_date ?? '';
                $dueDate = $document->due_date ?? '';
                $status = $document->status ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                $notes = $document->description ?? '';
                try {
                    $qrHtml = \DNS2D::getBarcodeHTML(route('invoice.link.copy', Crypt::encrypt($document->invoice_id)), 'QRCODE', 2, 2);
                } catch (\Throwable $e) {
                    $qrHtml = '';
                }
                break;

            case 'bill':
                $party = $party ?? ($document->vender ?? $document->vendor ?? null);
                $partyLabel = __('Bill From');
                $number = $document->bill_id ?? $document->id;
                $date = $document->bill_date ?? $document->issue_date ?? '';
                $dueDate = $document->due_date ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                $notes = $document->description ?? '';
                break;

            case 'purchase':
                $party = $party ?? ($document->vender ?? $extra['vendor'] ?? null);
                $partyLabel = __('Vendor');
                $number = $document->purchase_number ?? $document->purchase_id ?? $document->id;
                $date = $document->purchase_date ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                break;

            case 'quotation':
                $party = $party ?? $document->customer;
                $partyLabel = __('Customer');
                $number = $document->quotation_id ?? $document->id;
                $date = $document->quotation_date ?? $document->issue_date ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                $notes = $document->description ?? '';
                break;

            case 'pos':
                $party = $party ?? $document->customer;
                $partyLabel = __('Customer');
                $number = $document->pos_id ?? $document->id;
                $date = $document->created_at ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : (float) ($document->total ?? 0);
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = method_exists($document, 'getTotal') ? (float) $document->getTotal() : ($subTotal - $discountTotal + $taxTotal);
                break;
        }

        $banks = BankAccount::where('created_by', $document->created_by)
            ->orderBy('id')
            ->limit(5)
            ->get();

        return [
            'settings' => $settings,
            'color' => $color,
            'font_color' => $fontColor,
            'img' => $img,
            'party' => $party,
            'party_label' => $partyLabel,
            'number' => $number,
            'date' => $date,
            'due_date' => $dueDate,
            'status' => $status,
            'items' => $items,
            'sub_total' => $subTotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'notes' => $notes,
            'qr_html' => $qrHtml,
            'banks' => $banks,
            'document' => $document,
            'title' => DocumentLayoutCatalog::documentTypes()[$documentType] ?? ucfirst($documentType),
        ];
    }

    protected static function defaultLogo(array $settings, string $documentType): string
    {
        $logo = asset(Storage::url('uploads/logo/'));
        $companyLogo = Utility::getValByName('company_logo_dark');
        $key = $documentType . '_logo';
        if (! empty($settings[$key])) {
            return Utility::get_file($documentType . '_logo/') . $settings[$key];
        }

        return asset($logo . '/' . (! empty($companyLogo) ? $companyLogo : 'logo-dark.png'));
    }
}
