@php
    $settings = Utility::settings();
    $hasCreditPayment = !empty($posPayments) && $posPayments->isNotEmpty() && $posPayments->contains(function ($p) { return in_array(strtolower($p->payment_method ?? ''), ['credit']); });
    $paymentDocumentTitle = $hasCreditPayment ? __('Credit Sale') : __('Cash Sale');
    $creditPmt = $hasCreditPayment ? $posPayments->first(function ($p) { return strtolower($p->payment_method ?? '') === 'credit'; }) : null;
    $amountReceived = $posPayments ? ($posPayments->sum('amount') - ($creditPmt ? (float)$creditPmt->amount : 0)) : 0;
    $balance = $creditPmt ? (float)$creditPmt->amount : 0;
    $dueDate = $creditPmt && !empty($creditPmt->credit_terms) ? \Carbon\Carbon::now()->addDays((int)$creditPmt->credit_terms)->format(isset($settings['date_format']) ? $settings['date_format'] : 'Y-m-d') : '-';
    $logoPath = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::getValByName('company_logo_dark');
    $pos_logo = \App\Models\Utility::getValByName('pos_logo');
    $headerLogo = (isset($pos_logo) && $pos_logo) ? \App\Models\Utility::get_file('pos_logo/') . $pos_logo : $logoPath . '/' . ($company_logo ?? 'logo-dark.png');
    $docTitle = $hasCreditPayment ? __('Credit Receipt') : __('Tax Invoice');
    $clientName = isset($details['client_name']) ? $details['client_name'] : (isset($details['customer']['name']) ? $details['customer']['name'] : __('pos.walkin_customer'));
    $cust = $details['customer'] ?? [];
    $vatBreakdown = $vatBreakdown ?? [];
    $bankAccounts = $bankAccounts ?? collect();
@endphp
<style>
    .pos-print-header .header-sep { border-bottom: 1px dotted #333; margin: 10px 0; }
    .pos-print-header .logo-box { max-width: 120px; height: 70px; margin: 0 auto 8px; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff; }
    .pos-print-header .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .pos-print-header .doc-title { font-size: 1.25rem; font-weight: bold; text-align: center; margin: 8px 0; }
    .pos-print-header .doc-row { display: flex; justify-content: space-between; margin-bottom: 6px; }
    .pos-print-header .doc-row .left, .pos-print-header .doc-row .right { flex: 1; }
    .pos-print-header .doc-row .right { text-align: right; }
    .pos-print-header .customer-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
    .pos-print-header .customer-row .cust-type { font-weight: bold; }
    .pos-print-header .customer-row .cust-name { font-weight: bold; color: #333; }
    .pos-print-header .customer-details { font-size: 0.9rem; color: #555; margin-top: 4px; }
</style>
<div class="pt-0 pb-3 modal-body pos-module" id="printarea" >
    <div class="pos-print-header">
        {{-- Company: Logo --}}
        <div class="text-center">
            <div class="logo-box">
                <img src="{{ $headerLogo }}" alt="Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <span style="display:none; color:#22c55e; font-weight:bold;">LOGO</span>
            </div>
            <div>{{ $settings['company_name'] ?? '' }}</div>
            <div>{{ $settings['company_address'] ?? $settings['mail_from_address'] ?? '' }}</div>
            @if(!empty($settings['company_city']) || !empty($settings['company_state']) || !empty($settings['company_zipcode']))
            <div>{{ $settings['company_city'] ?? '' }}{{ !empty($settings['company_state']) ? ', ' . $settings['company_state'] : '' }}{{ !empty($settings['company_zipcode']) ? ' ' . $settings['company_zipcode'] : '' }}</div>
            @endif
            @if($settings['vat_gst_number_switch'] == 'on' && !empty($settings['vat_number']))
            <div>{{ $settings['tax_type'] ?? 'VAT' }} NR: {{ $settings['vat_number'] ?? '' }}</div>
            @endif
            <div>Phone: {{ $settings['company_telephone'] ?? '' }}</div>
            <div>E-mail: {{ $settings['mail_from_address'] ?? '' }}</div>
        </div>

        <div class="header-sep"></div>

        {{-- Date / Warehouse | Original-Copy / DOC NR --}}
        <div class="doc-row">
            <div class="left">
                <div>Date: {{ $details['date'] ?? date('Y-m-d') }}</div>
                <div class="mt-1">{{ __('pos.warehouse_name') }}:<br>{{ $details['warehouse']['name'] ?? '-' }}</div>
            </div>
            <div class="right">
                <div>Original/Copy</div>
                <div class="mt-1">DOC NR<br><strong>{{ $details['pos_id'] }}</strong></div>
            </div>
        </div>

        <div class="header-sep"></div>

        {{-- Customer: Walk-in Customer | Client Name --}}
        <div class="customer-row">
            <div class="cust-type">{{ __('pos.walkin_customer') }}</div>
            <div class="cust-name">{{ $clientName }}</div>
        </div>
        @if(!empty($cust['billing_address']) || !empty($cust['billing_phone']) || !empty($cust['email']))
        <div class="customer-details">
            @if(!empty($cust['billing_address']))<div>{{ $cust['billing_address'] }}</div>@endif
            @if($settings['vat_gst_number_switch'] == 'on' && !empty($cust['tax_number']))<div>VAT NR.: {{ $cust['tax_number'] }}</div>@endif
            @if(!empty($cust['billing_phone']))<div>Phone: {{ $cust['billing_phone'] }}</div>@endif
            @if(!empty($cust['email']))<div>E-mail: {{ $cust['email'] }}</div>@endif
        </div>
        @endif
        @if(!empty($details['table_name']))
        <div class="mt-1"><b>{{ __('pos.table') }}:</b> {{ $details['table_name'] }}</div>
        @endif

        <div class="header-sep"></div>
    </div>

    @if($hasCreditPayment)
        <div class="product-border mt-3 p-3">
            <div class="text-black fw-bold mb-2">{{ __('Credit Receipt') }}</div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Receipt reference') }}:</b></div>
                <div class="text-end ms-auto">{{ $details['pos_id'] }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Amount received') }}:</b></div>
                <div class="text-end ms-auto">{{ Auth::user()->priceFormat($amountReceived) }} <span class="text-muted">({{ __('pos.by_writing') }}: {{ \App\Models\Utility::amountInWords($amountReceived) }})</span></div>
            </div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Balance') }}:</b></div>
                <div class="text-end ms-auto">{{ Auth::user()->priceFormat($balance) }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div><b>{{ __('Due date') }}:</b></div>
                <div class="text-end ms-auto">{{ $dueDate }}</div>
            </div>
        </div>
    @else
    <div class=" text-black text-left fs-5 mt-0 mb-0">{{__('Items')}}</div>
        @foreach ($sales['data'] ?? [] as $key => $value)
            <div class="mt-2">
                <div class="p-0"> <b>{{ $value['name'] }}</b>@if(!empty($value['description']))<br><strong>{{ $value['description'] }}</strong>@endif</div>
                <div class="d-flex product-border">
                    <div>{{ __('pos.quantity_colon') }}</div>
                    <div class="text-end ms-auto">{{ $value['quantity'] }}</div>
                </div>
            </div>
            <div class="d-flex product-border">
                <div>{{ __('pos.price_colon') }}</div>
                <div class="text-end ms-auto">{{ $value['price'] }}</div>
            </div>
            <div class="d-flex product-border">
                <div>{{ __('pos.tax_colon') }}</div>
                <div class="text-end ms-auto"> {{ $value['tax'] }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div>{{ __('pos.tax_amount_colon') }}</div>
                <div class="text-end ms-auto">{{ $value['tax_amount'] }}</div>
            </div>
            <div class="d-flex product-border mb-2">
                <div>{{ __('pos.sub_total_colon') }}</div>
                <div class="text-end ms-auto"> {{ $value['subtotal'] }}</div>
            </div>
        @endforeach
        <div class="d-flex product-border mb-2 mt-4">
            <div><b>{{ __('pos.discount_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['discount'] }}</div>
        </div>
        @if(isset($sales['tip']) && $sales['tip'] !== '' && $sales['tip'] != '0' && $sales['tip'] != '0.00')
        <div class="d-flex product-border mb-2">
            <div><b>{{ __('pos.tip') }}:</b></div>
            <div class="text-end ms-auto"> {{ $sales['tip'] }}</div>
        </div>
        @endif
        <div class="d-flex product-border mb-2">
            <div><b>{{ __('pos.total_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['total'] }}</div>
        </div>
    @endif

        @if(!empty($posPayments) && $posPayments->isNotEmpty())
            <div class="product-border mb-2 mt-3 p-2">
                <div class="text-black fw-bold mb-2">{{ $paymentDocumentTitle }}</div>
                @foreach($posPayments as $pmt)
                    <div class="d-flex product-border mb-1">
                        <div>{{ __('Payment Method') }}:</div>
                        <div class="text-end ms-auto text-capitalize">{{ str_replace('_', ' ', $pmt->payment_method ?? '') }}</div>
                    </div>
                    <div class="d-flex product-border mb-1">
                        <div>{{ __('Amount') }}:</div>
                        <div class="text-end ms-auto">{{ Auth::user()->priceFormat($pmt->amount ?? 0) }}</div>
                    </div>
                    @if(isset($pmt->change_amount) && $pmt->change_amount > 0)
                        <div class="d-flex product-border mb-1">
                            <div><b>{{ __('Change') }}</b>:</div>
                            <div class="text-end ms-auto"><b>{{ Auth::user()->priceFormat($pmt->change_amount) }}</b></div>
                        </div>
                    @endif
                    @if(!empty($pmt->transaction_id))
                        <div class="d-flex product-border mb-1">
                            <div>{{ __('Transaction ID') }}:</div>
                            <div class="text-end ms-auto">{{ $pmt->transaction_id }}</div>
                        </div>
                    @endif
                    @if(!empty($pmt->credit_terms))
                        <div class="d-flex product-border mb-1">
                            <div>{{ __('Credit Days') }}:</div>
                            <div class="text-end ms-auto">{{ $pmt->credit_terms }}</div>
                        </div>
                    @endif
                    @if(!empty($pmt->notes))
                        <div class="product-border mb-1 p-1 small">
                            <div>{{ __('Notes') }}:</div>
                            <div>{{ $pmt->notes }}</div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- Footer: Bank details (only if we have banks) --}}
        @if($bankAccounts->isNotEmpty())
        <div class="pos-print-footer mt-3 p-2 product-border">
            <div class="fw-bold mb-1">{{ __('Bank Details') }}</div>
            @foreach($bankAccounts as $idx => $bank)
            <div class="small mb-1">Bank {{ $idx + 1 }}: {{ $bank->account_number ?? '-' }} @if(!empty($bank->bank_name)) ({{ $bank->bank_name }}) @endif</div>
            @endforeach
        </div>
        @endif

        {{-- VAT summary (incidence) - for MOZ etc. --}}
        @if(count($vatBreakdown) > 0)
        <div class="pos-print-footer mt-2">
            <table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;">
                <thead><tr><th>VAT %</th><th>{{ __('Net Amount') }}</th><th>VAT</th><th>{{ __('Amount') }}</th></tr></thead>
                <tbody>
                @foreach($vatBreakdown as $row)
                <tr>
                    <td>{{ number_format($row['rate'], 0) }}</td>
                    <td>{{ Auth::user()->priceFormat($row['net']) }}</td>
                    <td>{{ Auth::user()->priceFormat($row['vat']) }}</td>
                    <td>{{ Auth::user()->priceFormat($row['amount']) }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="text-center mt-3 small text-muted">
            <div>{{ __('pos.document_processed_by_computer') }}</div>
            <div class="mt-1">{{ __('pos.thank_you') }}</div>
        </div>

        {{-- Licensing / Certification --}}
        <div class="pos-print-footer mt-2 d-flex align-items-start gap-3">
            <div class="border rounded p-2 text-center" style="width:80px;height:80px;line-height:1.2;font-size:0.7rem;">DOC QR code</div>
            <div class="small">
                <div>Licensed for: {{ $settings['company_name'] ?? '' }}</div>
                <div>Certified by:</div>
                <div>Certificate NR:</div>
            </div>
        </div>

        <div class="text-end mt-2 small text-muted">
            ERP_SaaS - POS Restaurant<br>
            {{ date('H:i') }}
        </div>

        @if($hasCreditPayment)
        <div class="pos-print-footer mt-3 p-2 product-border">
            <div class="small mb-2">{{ __('pos.credit_signature_line') }}</div>
            <div class="border-bottom pb-1 mb-1" style="min-height: 28px;"></div>
            <div class="small text-muted">{{ __('Name') }}:</div>
        </div>
        @endif
</div>

    <div class="justify-content-center pt-2 modal-footer">
        <a href="#" id="print"
            class="btn btn-primary btn-sm text-right float-right mb-3 ">
            {{ __('pos.print') }}
        </a>
    </div>
<script>
    $("#print").click(function () {
        var print_div = document.getElementById("printarea");
        $('.row').addClass('d-none');
        $('.toast').addClass('d-none');
        $('#print').addClass('d-none');
        window.print();
        $('.row').removeClass('d-none');
        $('#print').removeClass('d-none');
        $('.toast').removeClass('d-none');
    });
</script>




