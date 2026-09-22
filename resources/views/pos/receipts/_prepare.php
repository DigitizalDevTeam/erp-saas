<?php

/** Shared prepare for POS compact receipts — include from parent view scope. */

$standalone = $standalone ?? false;
$settings = $settings ?? \App\Models\Utility::settings();
$posPayments = $posPayments ?? collect();
$logoBase = rtrim((string) \App\Models\Utility::get_file('uploads/logo'), '/');
$company_logo = \App\Models\Utility::getValByName('company_logo_dark');
$pos_logo = \App\Models\Utility::getValByName('pos_logo');
if (! empty($pos_logo)) {
    $headerLogo = rtrim((string) \App\Models\Utility::get_file('pos_logo'), '/') . '/' . ltrim((string) $pos_logo, '/');
} else {
    $headerLogo = $logoBase . '/' . ltrim((string) ($company_logo ?: 'logo-dark.png'), '/');
}
$companyName = $settings['company_name'] ?? config('app.name');
$posAccentColor = '#' . ltrim((string) ($settings['pos_color'] ?? '000000'), '#');
if (strlen(ltrim((string) ($settings['pos_color'] ?? ''), '#')) < 3) {
    $posAccentColor = '#000000';
}
$posQrDisplay = (($settings['pos_qr_display'] ?? 'on') !== 'off');
$clientName = isset($details['client_name']) ? $details['client_name'] : (isset($details['customer']['name']) ? $details['customer']['name'] : __('pos.walkin_customer'));
$cust = $details['customer'] ?? [];
$vatBreakdown = $vatBreakdown ?? [];
$bankAccounts = $bankAccounts ?? collect();
$segundaVia = $segundaVia ?? false;
$posIdRaw = $posIdRaw ?? null;
$receiptLinkUrl = $receiptLinkUrl ?? null;
$printSource = $printSource ?? 'retail';
$isBalancePayment = $isBalancePayment ?? false;
$balancePaymentExtra = $balancePaymentExtra ?? null;
$qrCodeContent = $receiptLinkUrl ?: (isset($details['pos_id']) ? (($settings['company_name'] ?? config('app.name')) . ' - ' . $details['pos_id'] . ' - ' . ($details['date'] ?? date('Y-m-d'))) : url('/'));
$receiptUser = $receiptUser ?? null;
$priceUser = $receiptUser ?? \Illuminate\Support\Facades\Auth::user();
$printLayout = \App\Models\Utility::normalizePosPrintLayout($settings['pos_print_layout'] ?? 'receipt');
// POS Layout 3 / compact slips are designed for 80mm thermal paper.
$receiptLayout = \App\Models\Utility::normalizeReceiptLayout($settings['pos_receipt_layout'] ?? 'existing');
if (in_array($receiptLayout, ['compact1', 'compact2', 'compact3'], true) && $printLayout === 'receipt') {
    $printLayout = 'slip';
}
$directPrint = (isset($settings['pos_direct_print']) && $settings['pos_direct_print'] === 'on');

$companyAddressParts = array_filter([
    $settings['company_address'] ?? null,
    $settings['company_city'] ?? null,
    $settings['company_state'] ?? null,
    $settings['company_zipcode'] ?? null,
    $settings['company_country'] ?? null,
]);
$companyAddressLine = implode(', ', $companyAddressParts);
$companyPhone = $settings['company_telephone'] ?? '';
$companyEmail = $settings['company_email'] ?? ($settings['mail_from_address'] ?? '');
$companyVat = $settings['vat_number'] ?? '';
$custAddress = $cust['billing_address'] ?? ($cust['address'] ?? '');
$custPhone = $cust['billing_phone'] ?? ($cust['contact'] ?? ($cust['phone'] ?? ''));
$custEmail = $cust['email'] ?? '';
$custVat = $cust['tax_number'] ?? '';
$currency = $settings['site_currency_symbol'] ?? ($settings['site_currency'] ?? 'MT');
$hasCreditPayment = ! empty($posPayments) && $posPayments->isNotEmpty() && $posPayments->contains(function ($p) {
    return in_array(strtolower($p->payment_method ?? ''), ['credit'], true);
});
// Slip title: document_type only — never derive from payment method.
$rawDocumentType = null;
if (isset($pos) && ! empty($pos->document_type)) {
    $rawDocumentType = $pos->document_type;
} elseif (! empty($details['document_type'])) {
    $rawDocumentType = $details['document_type'];
}
$posDocumentType = \App\Models\Pos::normalizeDocumentType($rawDocumentType);
$isCreditDocument = ($posDocumentType === \App\Models\Pos::DOCUMENT_TYPE_CREDIT_SALE) || $hasCreditPayment;
$paymentDocumentTitle = \App\Models\Pos::documentTypeLabel($posDocumentType);
$fmtMoney = function ($amount) use ($priceUser) {
    if ($priceUser && method_exists($priceUser, 'priceFormat')) {
        return $priceUser->priceFormat($amount);
    }

    return number_format((float) $amount, 2);
};
$paidTotal = 0.0;
$changeTotal = 0.0;
$creditTotal = 0.0;
$nonCreditPaidTotal = 0.0;
$paymentMethods = [];
$paymentRefs = [];
if (! empty($posPayments)) {
    foreach ($posPayments as $pmt) {
        $amt = (float) ($pmt->amount ?? 0);
        $paidTotal += $amt;
        $changeTotal += (float) ($pmt->change_amount ?? 0);
        if (strtolower($pmt->payment_method ?? '') === 'credit') {
            $creditTotal += $amt;
        } else {
            $nonCreditPaidTotal += $amt;
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
$paymentNotes = '';
if (! empty($posPayments)) {
    foreach ($posPayments as $pmt) {
        $note = trim((string) ($pmt->notes ?? ''));
        if ($note !== '') {
            $paymentNotes = $note;
            break;
        }
    }
}
$operatorName = $receiptUser->name ?? (\Illuminate\Support\Facades\Auth::user()->name ?? '—');
$branchName = $details['branch']['name'] ?? ($details['warehouse']['name'] ?? '—');
$docNumber = $details['pos_id'] ?? '—';
$docDate = $details['date'] ?? date('Y-m-d');
$docDateTime = $docDate . ' ' . date('H:i:s');
$copyLabel = ! empty($segundaVia) ? __('Copy') : __('Original');
$salesSubTotal = $sales['sub_total'] ?? ($sales['total'] ?? '0');
$salesDiscount = $sales['discount'] ?? '0';
$salesTotal = $sales['total'] ?? '0';
$ivaTotalNum = 0.0;
foreach ($vatBreakdown as $row) {
    $ivaTotalNum += (float) ($row['vat'] ?? 0);
}
$ivaTotalFmt = $fmtMoney($ivaTotalNum);

$parseMoneyPrep = function ($v) {
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
$saleTotalNum = $parseMoneyPrep($sales['total'] ?? 0);
if ($saleTotalNum <= 0 && ! empty($sales['data'])) {
    foreach ($sales['data'] as $line) {
        $saleTotalNum += $parseMoneyPrep($line['subtotal'] ?? 0);
    }
}

if ($isCreditDocument) {
    $dueAmount = max(
        0.0,
        $creditTotal > 0 ? $creditTotal : ($saleTotalNum - $nonCreditPaidTotal),
        $saleTotalNum - $nonCreditPaidTotal
    );
    if ($dueAmount <= 0 && $saleTotalNum > 0) {
        $dueAmount = $saleTotalNum;
    }
    $displayPaidTotal = $nonCreditPaidTotal;
    $showPaidRow = $displayPaidTotal > 0;
    $showPendente = true;
    $trocoPendenteLabel = __('Due Amount');
    $trocoPendenteValue = $fmtMoney($dueAmount);
    $paidFmt = $fmtMoney($displayPaidTotal);
    $hasCreditPayment = true;
} else {
    $displayPaidTotal = $paidTotal;
    $showPaidRow = true;
    $showPendente = false;
    $trocoPendenteLabel = __('Troco');
    $trocoPendenteValue = $fmtMoney($changeTotal);
    $paidFmt = $fmtMoney($paidTotal);
}
$hasPayments = ! empty($posPayments) && $posPayments->isNotEmpty() && $paidTotal > 0;
$companyWebsite = $settings['company_website'] ?? 'www.uthara.co.mz';
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
