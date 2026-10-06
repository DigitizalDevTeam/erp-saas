<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Pos;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Client UTHARA Mozambique print pack (Factura A4/A5, Talão 80mm, Mapa inventário).
 */
class UtharaPrintRenderer
{
    public static function isEnabled(?array $settings = null): bool
    {
        $settings = $settings ?? Utility::settings();
        $flag = $settings['uthara_print_pack'] ?? 'on';

        return $flag !== 'off';
    }

    public static function facturaPaper(?array $settings = null): string
    {
        $settings = $settings ?? Utility::settings();
        $paper = strtolower((string) ($settings['uthara_factura_paper'] ?? 'a4'));

        return in_array($paper, ['a4', 'a5'], true) ? $paper : 'a4';
    }

    /**
     * Prefer UTHARA factura for invoice / quotation / bill when pack is enabled.
     *
     * @param  Invoice|Bill|Quotation|Purchase|Pos  $document
     * @return \Illuminate\Contracts\View\View|null
     */
    public static function tryRenderFactura(string $documentType, $document, array $extra = [])
    {
        $settings = $extra['settings'] ?? Utility::settingsById((int) ($document->created_by ?? 0));
        if (! self::isEnabled($settings)) {
            return null;
        }
        if (! in_array($documentType, ['invoice', 'quotation', 'bill', 'purchase'], true)) {
            return null;
        }

        $payload = self::buildFacturaPayload($documentType, $document, $extra + ['settings' => $settings]);

        return view('print.uthara.factura', $payload);
    }

    /**
     * @param  Invoice|Bill|Quotation|Purchase  $document
     */
    public static function buildFacturaPayload(string $documentType, $document, array $extra = []): array
    {
        $settings = $extra['settings'] ?? Utility::settingsById((int) $document->created_by);
        $paperSize = self::facturaPaper($settings);
        $user = Auth::user();
        $fmt = function ($amount) use ($user) {
            if ($user && method_exists($user, 'priceFormat')) {
                return $user->priceFormat($amount);
            }

            return number_format((float) $amount, 2, ',', '.');
        };

        $titles = [
            'invoice' => __('Invoice'),
            'quotation' => __('Quotation'),
            'bill' => __('Bill'),
            'purchase' => __('Purchase'),
        ];
        $docTitle = $titles[$documentType] ?? ucfirst($documentType);

        $party = $extra['party'] ?? null;
        $number = '';
        $date = '';
        $dueDate = '';
        $status = '';
        $items = [];
        $subTotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;
        $notes = '';
        $paidTotal = 0.0;
        $qrHtml = '';
        $qrSvg = null;
        $refNumber = '';
        $orderNumber = '';

        switch ($documentType) {
            case 'invoice':
                /** @var Invoice $document */
                $party = $party ?? $document->customer;
                $number = method_exists($user, 'invoiceNumberFormat')
                    ? $user->invoiceNumberFormat($document->invoice_id)
                    : ($document->invoice_id ?? $document->id);
                $date = $document->issue_date ?? '';
                $dueDate = $document->due_date ?? '';
                $status = $document->status ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                $paidTotal = method_exists($document, 'getTotalPayment') ? (float) $document->getTotalPayment() : 0;
                $notes = $document->description ?? '';
                $refNumber = $document->ref_number ?? '';
                $orderNumber = $document->client_po_number ?? '';
                try {
                    $link = route('invoice.link.copy', Crypt::encrypt($document->invoice_id));
                    $qrSvg = \DNS2D::getBarcodeSVG($link, 'QRCODE', 2, 2);
                    $qrHtml = \DNS2D::getBarcodeHTML($link, 'QRCODE', 2, 2);
                } catch (\Throwable $e) {
                    $qrSvg = null;
                    $qrHtml = '';
                }
                break;

            case 'quotation':
                $party = $party ?? $document->customer;
                $number = method_exists($user, 'quotationNumberFormat')
                    ? $user->quotationNumberFormat($document->quotation_id)
                    : ($document->quotation_id ?? $document->id);
                $date = $document->quotation_date ?? $document->issue_date ?? '';
                $dueDate = $document->due_date ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                $notes = $document->description ?? '';
                break;

            case 'bill':
                $party = $party ?? ($document->vender ?? $document->vendor ?? null);
                $number = method_exists($user, 'billNumberFormat')
                    ? $user->billNumberFormat($document->bill_id)
                    : ($document->bill_id ?? $document->id);
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
                $number = method_exists($user, 'purchaseNumberFormat')
                    ? $user->purchaseNumberFormat($document->purchase_id)
                    : ($document->purchase_number ?? $document->purchase_id ?? $document->id);
                $date = $document->purchase_date ?? '';
                $items = $document->itemData ?? $document->items ?? [];
                $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : 0;
                $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : 0;
                $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : 0;
                $grandTotal = $subTotal - $discountTotal + $taxTotal;
                break;
        }

        $lines = [];
        $vatBreakdown = [];
        $i = 0;
        foreach ($items as $row) {
            $i++;
            $name = is_object($row)
                ? ($row->product->name ?? $row->name ?? ('#' . ($row->product_id ?? $i)))
                : ($row['name'] ?? '');
            $desc = is_object($row) ? (string) ($row->description ?? '') : (string) ($row['description'] ?? '');
            $qty = (float) (is_object($row) ? ($row->quantity ?? 0) : ($row['quantity'] ?? 0));
            $price = (float) (is_object($row) ? ($row->price ?? 0) : ($row['price'] ?? 0));
            $discount = (float) (is_object($row) ? ($row->discount ?? 0) : ($row['discount'] ?? 0));
            $lineNet = ($price * $qty) - $discount;
            $taxRate = 0.0;
            $product = is_object($row) ? ($row->product ?? null) : null;
            $taxField = is_object($row) ? ($row->tax ?? null) : ($row['tax'] ?? null);
            if ($product && ! empty($product->tax_id)) {
                $taxIds = array_filter(array_map('trim', explode(',', (string) $product->tax_id)));
                if ($taxIds !== []) {
                    foreach (\App\Models\Tax::whereIn('id', $taxIds)->get() as $t) {
                        $taxRate += (float) ($t->rate ?? 0);
                    }
                }
            } elseif (is_numeric($taxField) && (float) $taxField <= 100) {
                $taxRate = (float) $taxField;
            } elseif (is_string($taxField) && str_contains($taxField, ',')) {
                $taxIds = array_filter(array_map('trim', explode(',', $taxField)));
                foreach (\App\Models\Tax::whereIn('id', $taxIds)->get() as $t) {
                    $taxRate += (float) ($t->rate ?? 0);
                }
            } elseif (is_numeric($taxField)) {
                $maybe = \App\Models\Tax::find((int) $taxField);
                $taxRate = $maybe ? (float) ($maybe->rate ?? 0) : 0.0;
            }
            if (is_object($row) && isset($row->taxRate)) {
                $taxRate = (float) $row->taxRate;
            }
            $lineTax = $lineNet * ($taxRate / 100);
            $lines[] = [
                'index' => $i,
                'name' => $name,
                'description' => $desc,
                'quantity' => $qty,
                'price' => $fmt($price),
                'price_raw' => $price,
                'unit_incl_vat' => $qty > 0 ? $fmt(($lineNet + $lineTax) / $qty) : $fmt($price * (1 + $taxRate / 100)),
                'unit_incl_vat_raw' => $qty > 0 ? ($lineNet + $lineTax) / $qty : $price * (1 + $taxRate / 100),
                'total' => $fmt($lineNet + $lineTax),
                'total_raw' => $lineNet + $lineTax,
                'tax_rate' => $taxRate,
            ];
            $rateKey = (string) round($taxRate, 2);
            if (! isset($vatBreakdown[$rateKey])) {
                $vatBreakdown[$rateKey] = ['rate' => $taxRate, 'net' => 0.0, 'vat' => 0.0];
            }
            $vatBreakdown[$rateKey]['net'] += $lineNet;
            $vatBreakdown[$rateKey]['vat'] += $lineTax;
        }
        if ($taxTotal > 0 && array_sum(array_column($vatBreakdown, 'vat')) <= 0) {
            $vatBreakdown['default'] = ['rate' => 0, 'net' => $subTotal - $discountTotal, 'vat' => $taxTotal];
        }

        $logo = $extra['img'] ?? self::companyLogo($settings, $documentType);
        $companyName = $settings['company_name'] ?? config('app.name');
        $companyAddress = implode(', ', array_filter([
            $settings['company_address'] ?? null,
            $settings['company_city'] ?? null,
            $settings['company_state'] ?? null,
            $settings['company_zipcode'] ?? null,
            $settings['company_country'] ?? null,
        ])) ?: '—';
        $currency = $settings['site_currency_symbol'] ?? ($settings['site_currency'] ?? 'MT');

        $partyName = is_object($party) ? ($party->name ?? '—') : '—';
        $partyNuit = is_object($party) ? ($party->tax_number ?? '') : '';
        $partyAddress = is_object($party)
            ? ($party->billing_address ?? $party->address ?? '')
            : '';
        $partyContact = is_object($party)
            ? ($party->contact ?? $party->billing_phone ?? $party->phone ?? '')
            : '';
        $partyEmail = is_object($party) ? ($party->email ?? '') : '';

        $banks = BankAccount::where('created_by', $document->created_by)->orderBy('id')->limit(5)->get();
        $pending = max(0, $grandTotal - $paidTotal);

        $statusLabel = '';
        if ($documentType === 'invoice') {
            $statusLabel = $paidTotal >= $grandTotal && $grandTotal > 0
                ? __('Paid')
                : ($paidTotal > 0 ? __('Partial') : __('Unpaid'));
        }

        return [
            'paperSize' => $paperSize,
            'preview' => (bool) ($extra['preview'] ?? false),
            'documentType' => $documentType,
            'docTitle' => $docTitle,
            'docNumber' => $number,
            'docSeries' => $settings[$documentType . '_prefix'] ?? '',
            'docDate' => $date,
            'docTime' => now()->format('H:i'),
            'dueDate' => $dueDate,
            'currency' => $currency,
            'copyLabel' => __('Original'),
            'companyLogo' => $logo,
            'companyName' => $companyName,
            'companyBrand' => strtoupper(substr((string) $companyName, 0, 12)) ?: 'UTHARA',
            'companyAddress' => $companyAddress,
            'companyNuit' => $settings['vat_number'] ?? '',
            'companyPhone' => $settings['company_telephone'] ?? '',
            'companyEmail' => $settings['company_email'] ?? '',
            'companyWebsite' => $settings['company_website'] ?? 'www.uthara.co.mz',
            'partyName' => $partyName,
            'partyNuit' => $partyNuit,
            'partyAddress' => $partyAddress,
            'partyContact' => $partyContact ?: $partyEmail,
            'refNumber' => $refNumber,
            'orderNumber' => $orderNumber,
            'statusLabel' => $statusLabel,
            'lines' => $lines,
            'vatBreakdown' => array_values($vatBreakdown),
            'subTotal' => $fmt($subTotal),
            'discountTotal' => $fmt($discountTotal),
            'taxTotal' => $fmt($taxTotal),
            'grandTotal' => $fmt($grandTotal),
            'grandTotalRaw' => $grandTotal,
            'paidTotal' => $fmt($paidTotal),
            'pendingTotal' => $fmt($pending),
            'notes' => $notes,
            'exemptionReason' => '',
            'paymentMethod' => '—',
            'paymentRef' => $refNumber ?: '—',
            'operatorName' => $user->name ?? '—',
            'cashierLabel' => '—',
            'banks' => $banks,
            'qrSvg' => $qrSvg,
            'qrHtml' => $qrHtml,
            'validationUrl' => '',
            'validationCode' => $number,
            'documentHash' => '',
            'fmt' => $fmt,
            'settings' => $settings,
        ];
    }

    protected static function companyLogo(array $settings, string $documentType): string
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
