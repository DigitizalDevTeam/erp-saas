<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Utility;

/** @var array $details */
/** @var array $sales */

$standalone = $standalone ?? false;
$settings = $settings ?? Utility::settings();
$posPayments = $posPayments ?? collect();
$vatBreakdown = $vatBreakdown ?? [];
$bankAccounts = $bankAccounts ?? collect();
$segundaVia = $segundaVia ?? false;
$posIdRaw = $posIdRaw ?? null;
$receiptLinkUrl = $receiptLinkUrl ?? null;
$printSource = $printSource ?? 'retail';
$isBalancePayment = $isBalancePayment ?? false;
$balancePaymentExtra = $balancePaymentExtra ?? null;
$receiptUser = $receiptUser ?? null;
$priceUser = $receiptUser ?? Auth::user();
$printLayout = Utility::normalizePosPrintLayout($settings['pos_print_layout'] ?? 'receipt');
// For existing printview + restaurant, prefer 80mm slip paper when not already set.
if ($printSource === 'restaurant' && $printLayout === 'receipt') {
    $printLayout = 'slip';
}
$directPrint = (isset($settings['pos_direct_print']) && $settings['pos_direct_print'] === 'on');

$hasCreditPayment = ! empty($posPayments) && $posPayments->isNotEmpty() && $posPayments->contains(function ($p) {
    return in_array(strtolower($p->payment_method ?? ''), ['credit'], true);
});
$paymentDocumentTitle = $hasCreditPayment ? __('Credit Sale') : __('Cash Sale');
if ($isBalancePayment) {
    $paymentDocumentTitle = __('Receipt');
}
$creditPmt = $hasCreditPayment ? $posPayments->first(function ($p) {
    return strtolower($p->payment_method ?? '') === 'credit';
}) : null;
$dueDate = $creditPmt && ! empty($creditPmt->credit_terms)
    ? \Carbon\Carbon::now()->addDays((int) $creditPmt->credit_terms)->format($settings['date_format'] ?? 'Y-m-d')
    : null;

$logoBase = rtrim((string) Utility::get_file('uploads/logo'), '/');
$company_logo = Utility::getValByName('company_logo_dark');
$pos_logo = Utility::getValByName('pos_logo');
if (! empty($pos_logo)) {
    $headerLogo = rtrim((string) Utility::get_file('pos_logo'), '/') . '/' . ltrim((string) $pos_logo, '/');
} else {
    $headerLogo = $logoBase . '/' . ltrim((string) ($company_logo ?: 'logo-dark.png'), '/');
}

$clientName = $details['client_name']
    ?? ($details['customer']['name'] ?? __('pos.walkin_customer'));
$cust = $details['customer'] ?? [];

$companyName = $settings['company_name'] ?? config('app.name');
$companyAddressParts = array_filter([
    $settings['company_address'] ?? null,
    $settings['company_city'] ?? null,
    $settings['company_state'] ?? null,
    $settings['company_zipcode'] ?? null,
    $settings['company_country'] ?? null,
]);
$companyAddressLine = implode(', ', $companyAddressParts) ?: '—';
$companyPhone = $settings['company_telephone'] ?? '';
$companyEmail = $settings['company_email'] ?? ($settings['mail_from_address'] ?? '');
$companyVat = $settings['vat_number'] ?? '';
$companyWebsite = $settings['company_website'] ?? 'www.uthara.co.mz';

$custAddress = $cust['billing_address'] ?? ($cust['address'] ?? '');
$custPhone = $cust['billing_phone'] ?? ($cust['contact'] ?? ($cust['phone'] ?? ''));
$custEmail = $cust['email'] ?? '';
$custVat = $cust['tax_number'] ?? '';

$currency = $settings['site_currency_symbol'] ?? ($settings['site_currency'] ?? 'MT');
$docNumber = $details['pos_id'] ?? '—';
$docDate = $details['date'] ?? date('Y-m-d');
$docDateTime = $docDate . ' ' . date('H:i:s');
$operatorName = $receiptUser->name ?? (Auth::user()->name ?? '—');
$operatorEmail = $receiptUser->email ?? (Auth::user()->email ?? $companyEmail);
$branchName = $details['branch']['name'] ?? ($details['warehouse']['name'] ?? '—');
$copyLabel = $segundaVia ? __('Copy') : __('Original');

$qrCodeContent = $receiptLinkUrl
    ?: (isset($details['pos_id'])
        ? (($companyName) . ' - ' . $details['pos_id'] . ' - ' . $docDate)
        : url('/'));

$paidTotal = 0.0;
$changeTotal = 0.0;
$creditTotal = 0.0;
$paymentMethods = [];
$paymentRefs = [];
if (! empty($posPayments)) {
    foreach ($posPayments as $pmt) {
        $paidTotal += (float) ($pmt->amount ?? 0);
        $changeTotal += (float) ($pmt->change_amount ?? 0);
        if (strtolower($pmt->payment_method ?? '') === 'credit') {
            $creditTotal += (float) ($pmt->amount ?? 0);
        }
        if (! empty($pmt->payment_method)) {
            $paymentMethods[] = str_replace('_', ' ', $pmt->payment_method);
        }
        if (! empty($pmt->transaction_id)) {
            $paymentRefs[] = $pmt->transaction_id;
        }
    }
}
$paymentMethodLabel = $paymentMethods ? implode(', ', array_unique($paymentMethods)) : '—';
$paymentRefLabel = $paymentRefs ? implode(', ', $paymentRefs) : '—';

$fmt = function ($amount) use ($priceUser) {
    if ($priceUser && method_exists($priceUser, 'priceFormat')) {
        return $priceUser->priceFormat($amount);
    }

    return number_format((float) $amount, 2);
};

$salesSubTotal = $sales['sub_total'] ?? ($sales['total'] ?? '0');
$salesDiscount = $sales['discount'] ?? '0';
$salesTotal = $sales['total'] ?? '0';
$salesTip = $sales['tip'] ?? null;

$ivaTotalNum = 0.0;
foreach ($vatBreakdown as $row) {
    $ivaTotalNum += (float) ($row['vat'] ?? 0);
}

$parseMoney = function ($v) {
    if (is_numeric($v)) {
        return (float) $v;
    }
    $s = preg_replace('/[^\d.,\-]/', '', (string) $v);
    if ($s === null || $s === '') {
        return 0.0;
    }
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace(',', '', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }

    return (float) $s;
};

if ($ivaTotalNum <= 0 && ! empty($sales['data'])) {
    foreach ($sales['data'] as $line) {
        $ivaTotalNum += $parseMoney($line['tax_amount'] ?? 0);
    }
}
$ivaTotalFmt = $fmt($ivaTotalNum);

$paidFmt = $fmt($paidTotal);
$showTroco = $changeTotal > 0 && ! $hasCreditPayment;
$showPendente = $hasCreditPayment || ($creditTotal > 0);
$trocoPendenteLabel = $showPendente ? __('Pendente') : __('Troco');
$trocoPendenteValue = $showPendente
    ? $fmt($creditTotal > 0 ? $creditTotal : 0)
    : $fmt($changeTotal);

$saleTotalNum = $parseMoney($sales['total'] ?? 0);
if ($saleTotalNum <= 0 && ! empty($sales['data'])) {
    foreach ($sales['data'] as $line) {
        $saleTotalNum += $parseMoney($line['subtotal'] ?? 0);
    }
}
$amountInWordsSource = $saleTotalNum > 0 ? $saleTotalNum : ($paidTotal > 0 ? $paidTotal : 0);
$amountInWords = Utility::amountInWords($amountInWordsSource);

$hasPayments = ! empty($posPayments) && $posPayments->isNotEmpty() && $paidTotal > 0;

$qrSvg = null;
$qrHtml = '';
try {
    $qrSvg = \DNS2D::getBarcodeSVG($qrCodeContent, 'QRCODE', 2, 2);
} catch (\Throwable $e) {
    $qrSvg = null;
}
if (empty($qrSvg)) {
    try {
        $qrHtml = \DNS2D::getBarcodeHTML($qrCodeContent, 'QRCODE', 2, 2);
    } catch (\Throwable $e) {
        $qrHtml = '';
    }
}

$barcodeSvg = null;
$barcodeCode = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $docNumber) ?: '0';
try {
    $barcodeSvg = \DNS1D::getBarcodeSVG($barcodeCode, 'C128', 1.2, 40);
} catch (\Throwable $e) {
    $barcodeSvg = null;
}

$standardPartial = match ($printLayout) {
    'slip' => 'pos.standard.talao_80mm',
    'a5' => 'pos.standard.factura_a5',
    default => 'pos.standard.factura_a4',
};
