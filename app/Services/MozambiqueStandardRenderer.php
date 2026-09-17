<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

/**
 * Builds view data for Mozambique Standard A4/A5 print templates (Print Settings).
 */
class MozambiqueStandardRenderer
{
    public static function paperFromTemplate(string $template): string
    {
        $sizes = Utility::templateData()['template_sizes'] ?? [];

        return ($sizes[$template] ?? 'a4') === 'a5' ? 'a5' : 'a4';
    }

    /**
     * @param  object  $document
     * @param  object|null  $party
     */
    public static function build(string $documentType, $document, array $extra = []): array
    {
        $settings = $extra['settings'] ?? Utility::settings();
        $paperSize = strtolower((string) ($extra['paperSize'] ?? 'a4'));
        $paperSize = in_array($paperSize, ['a4', 'a5'], true) ? $paperSize : 'a4';

        if (in_array($documentType, ['invoice', 'bill'], true)) {
            $payload = UtharaPrintRenderer::buildFacturaPayload($documentType, $document, $extra + [
                'settings' => $settings,
            ]);
            $payload['paperSize'] = $paperSize;
            $payload['showQr'] = self::qrEnabled($documentType, $settings);
            if (! $payload['showQr']) {
                $payload['qrSvg'] = null;
                $payload['qrHtml'] = '';
            }

            $party = $extra['party'] ?? null;
            if (is_object($party)) {
                if (($payload['partyName'] ?? '—') === '—') {
                    $payload['partyName'] = $party->name ?? $party->billing_name ?? $party->shipping_name ?? '—';
                }
                if (($payload['partyAddress'] ?? '') === '') {
                    $payload['partyAddress'] = $party->billing_address ?? $party->shipping_address ?? $party->address ?? '';
                }
                if (($payload['partyContact'] ?? '') === '') {
                    $payload['partyContact'] = $party->contact ?? $party->billing_phone ?? $party->shipping_phone ?? $party->email ?? '';
                }
            }

            return $payload;
        }

        return self::buildProposalPayload($document, $extra + [
            'settings' => $settings,
            'paperSize' => $paperSize,
        ]);
    }

    /**
     * @param  object  $document
     */
    protected static function buildProposalPayload($document, array $extra = []): array
    {
        $settings = $extra['settings'] ?? Utility::settings();
        $paperSize = strtolower((string) ($extra['paperSize'] ?? 'a4'));
        $paperSize = in_array($paperSize, ['a4', 'a5'], true) ? $paperSize : 'a4';
        $user = Auth::user();
        $fmt = function ($amount) use ($user) {
            if ($user && method_exists($user, 'priceFormat')) {
                return $user->priceFormat($amount);
            }

            return number_format((float) $amount, 2, ',', '.');
        };

        $party = $extra['party'] ?? ($document->customer ?? null);
        $number = method_exists($user, 'proposalNumberFormat')
            ? $user->proposalNumberFormat($document->proposal_id ?? $document->id ?? 1)
            : ($document->proposal_id ?? $document->id ?? 1);
        $date = $document->issue_date ?? $document->proposal_date ?? '';
        $dueDate = $document->due_date ?? '';
        $items = $document->itemData ?? $document->items ?? [];
        $subTotal = method_exists($document, 'getSubTotal') ? (float) $document->getSubTotal() : self::sumItems($items, 'price');
        $discountTotal = method_exists($document, 'getTotalDiscount') ? (float) $document->getTotalDiscount() : self::sumItems($items, 'discount');
        $taxTotal = method_exists($document, 'getTotalTax') ? (float) $document->getTotalTax() : (float) ($document->totalTaxPrice ?? 0);
        $grandTotal = $subTotal - $discountTotal + $taxTotal;
        $notes = $document->description ?? '';

        $lines = [];
        $vatBreakdown = [];
        $i = 0;
        foreach ($items as $row) {
            $i++;
            $name = is_object($row) ? ($row->name ?? ('#' . $i)) : ($row['name'] ?? '');
            $desc = is_object($row) ? (string) ($row->description ?? '') : (string) ($row['description'] ?? '');
            $qty = (float) (is_object($row) ? ($row->quantity ?? 0) : ($row['quantity'] ?? 0));
            $price = (float) (is_object($row) ? ($row->price ?? 0) : ($row['price'] ?? 0));
            $discount = (float) (is_object($row) ? ($row->discount ?? 0) : ($row['discount'] ?? 0));
            $lineNet = ($price * $qty) - $discount;
            $taxRate = 0.0;
            if (is_object($row) && ! empty($row->itemTax) && is_array($row->itemTax)) {
                foreach ($row->itemTax as $t) {
                    $taxRate += (float) ($t['rate'] ?? 0);
                }
            }
            $lineTax = $lineNet * ($taxRate / 100);
            $lines[] = [
                'index' => $i,
                'name' => $name,
                'description' => $desc,
                'quantity' => $qty,
                'price' => $fmt($price),
                'total' => $fmt($lineNet + $lineTax),
            ];
            $rateKey = (string) round($taxRate, 2);
            if (! isset($vatBreakdown[$rateKey])) {
                $vatBreakdown[$rateKey] = ['rate' => $taxRate, 'net' => 0.0, 'vat' => 0.0];
            }
            $vatBreakdown[$rateKey]['net'] += $lineNet;
            $vatBreakdown[$rateKey]['vat'] += $lineTax;
        }

        $logo = $extra['img'] ?? '';
        $companyName = $settings['company_name'] ?? config('app.name');
        $companyAddress = implode(', ', array_filter([
            $settings['company_address'] ?? null,
            $settings['company_city'] ?? null,
            $settings['company_state'] ?? null,
            $settings['company_zipcode'] ?? null,
            $settings['company_country'] ?? null,
        ])) ?: '—';

        $partyName = is_object($party) ? ($party->name ?? $party->billing_name ?? '—') : '—';
        $partyNuit = is_object($party) ? ($party->tax_number ?? '') : '';
        $partyAddress = is_object($party)
            ? ($party->billing_address ?? $party->address ?? '')
            : '';
        $partyContact = is_object($party)
            ? ($party->contact ?? $party->billing_phone ?? $party->phone ?? $party->email ?? '')
            : '';

        $qrSvg = null;
        $qrHtml = '';
        $showQr = self::qrEnabled('proposal', $settings);
        if ($showQr) {
            try {
                $link = route('proposal.link.copy', Crypt::encrypt($document->id ?? $document->proposal_id ?? 0));
                $qrSvg = \DNS2D::getBarcodeSVG($link, 'QRCODE', 2, 2);
                $qrHtml = \DNS2D::getBarcodeHTML($link, 'QRCODE', 2, 2);
            } catch (\Throwable $e) {
                $qrSvg = null;
                $qrHtml = '';
            }
        }

        $createdBy = $document->created_by ?? (Auth::user()->creatorId() ?? 0);
        $banks = BankAccount::where('created_by', $createdBy)->orderBy('id')->limit(5)->get();

        return [
            'paperSize' => $paperSize,
            'preview' => (bool) ($extra['preview'] ?? false),
            'documentType' => 'proposal',
            'docTitle' => __('Proposal'),
            'docNumber' => $number,
            'docSeries' => $settings['proposal_prefix'] ?? '',
            'docDate' => $date,
            'docTime' => now()->format('H:i'),
            'dueDate' => $dueDate,
            'currency' => $settings['site_currency_symbol'] ?? ($settings['site_currency'] ?? 'MT'),
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
            'partyContact' => $partyContact,
            'refNumber' => $document->ref_number ?? '',
            'orderNumber' => '',
            'statusLabel' => '',
            'lines' => $lines,
            'vatBreakdown' => array_values($vatBreakdown),
            'subTotal' => $fmt($subTotal),
            'discountTotal' => $fmt($discountTotal),
            'taxTotal' => $fmt($taxTotal),
            'grandTotal' => $fmt($grandTotal),
            'grandTotalRaw' => $grandTotal,
            'paidTotal' => $fmt(0),
            'pendingTotal' => $fmt($grandTotal),
            'notes' => $notes,
            'exemptionReason' => '',
            'paymentMethod' => '—',
            'paymentRef' => '—',
            'operatorName' => $user->name ?? '—',
            'cashierLabel' => '—',
            'banks' => $banks,
            'qrSvg' => $qrSvg,
            'qrHtml' => $qrHtml,
            'showQr' => $showQr,
            'validationUrl' => '',
            'validationCode' => $number,
            'documentHash' => '',
            'fmt' => $fmt,
            'settings' => $settings,
        ];
    }

    protected static function qrEnabled(string $documentType, array $settings): bool
    {
        $key = match ($documentType) {
            'invoice' => 'invoice_qr_display',
            'bill' => 'bill_qr_display',
            default => 'qr_display',
        };

        return ($settings[$key] ?? 'on') === 'on';
    }

    protected static function sumItems($items, string $field): float
    {
        $sum = 0.0;
        foreach ($items as $row) {
            $val = is_object($row) ? ($row->{$field} ?? 0) : ($row[$field] ?? 0);
            if ($field === 'price') {
                $qty = is_object($row) ? ($row->quantity ?? 1) : ($row['quantity'] ?? 1);
                $sum += (float) $val * (float) $qty;
            } else {
                $sum += (float) $val;
            }
        }

        return $sum;
    }
}
