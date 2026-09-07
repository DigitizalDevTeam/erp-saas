<?php

/** Shared prepare for POS compact receipts — include from parent view scope. */

$standalone = $standalone ?? false;
$settings = $settings ?? \App\Models\Utility::settings();
$posPayments = $posPayments ?? collect();
$logoPath = asset(\Illuminate\Support\Facades\Storage::url('uploads/logo/'));
$company_logo = \App\Models\Utility::getValByName('company_logo_dark');
$pos_logo = \App\Models\Utility::getValByName('pos_logo');
$headerLogo = (isset($pos_logo) && $pos_logo) ? \App\Models\Utility::get_file('pos_logo/') . $pos_logo : $logoPath . '/' . ($company_logo ?? 'logo-dark.png');
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
$paymentDocumentTitle = $hasCreditPayment ? __('Credit Sale') : __('Cash Sale');
$paidTotal = 0.0;
$changeTotal = 0.0;
$creditTotal = 0.0;
if (! empty($posPayments)) {
    foreach ($posPayments as $pmt) {
        $paidTotal += (float) ($pmt->amount ?? 0);
        $changeTotal += (float) ($pmt->change_amount ?? 0);
        if (strtolower($pmt->payment_method ?? '') === 'credit') {
            $creditTotal += (float) ($pmt->amount ?? 0);
        }
    }
}
$fmtMoney = function ($amount) use ($priceUser) {
    if ($priceUser && method_exists($priceUser, 'priceFormat')) {
        return $priceUser->priceFormat($amount);
    }

    return number_format((float) $amount, 2);
};
$showPendente = $hasCreditPayment || $creditTotal > 0;
$trocoPendenteLabel = $showPendente ? __('Pendente') : __('Troco');
$trocoPendenteValue = $showPendente
    ? $fmtMoney($creditTotal > 0 ? $creditTotal : 0)
    : $fmtMoney($changeTotal);
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
