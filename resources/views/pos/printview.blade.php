@php
    $settings = Utility::settings();
    $hasCreditPayment = !empty($posPayments) && $posPayments->isNotEmpty() && $posPayments->contains(function ($p) { return in_array(strtolower($p->payment_method ?? ''), ['credit']); });
    $paymentDocumentTitle = $hasCreditPayment ? __('Credit Sale') : __('Cash Sale');
@endphp
<div class="pt-0 pb-3 modal-body pos-module" id="printarea" >
    <table class="table pos-module-tbl">
        <tbody>
            <div class="text-center ">
                <h3>{{ $settings['company_name'] }}</h3>
                <h4 class="text-dark mt-1 mb-0">{{ __('Bill') }}</h4>
            </div>
            <br>

            <div class="text-left">
                <b>{{ $details['pos_id'] }}</b>
            </div>
            <div class="text-left">
                {{$settings['company_name']}}<br>
                {{$settings['mail_from_address'] }}<br>
                {{$settings['company_address'] }}<br>
                {{$settings['company_city']}},
                {{$settings['company_state'] }},
                {{$settings['company_zipcode'] }}<br>
                {{$settings['company_country'] }}<br>
                {{$settings['company_telephone'] }}<br>
            </div>
            <div class="invoice-to mt-2 product-border" >
                {!! isset($details['customer']['name']) ? '' : $details['customer']['details'] !!}
            </div><br>
            <div>
                {!! isset($details['customer']['name']) ? __('pos.name').':  ' . $details['customer']['name'] : '' !!}
            </div>
            <div>
                {!! isset($details['customer']['address']) ? __('pos.address').':  ' . $details['customer']['address'] : '' !!}
            </div>
            <div>
                {!! isset($details['customer']['email']) ? __('Email').':  ' . $details['customer']['email'] : '' !!}
            </div>
            <div>
                {!! isset($details['customer']['phone_number']) ? __('pos.phone').':  ' . $details['customer']['phone_number'] : '' !!}
            </div>
            <div>
                {!! isset($details['date']) ? __('pos.date_of_pos').':  ' . $details['date'] : '' !!}
            </div>
            <div class="product-border">
                {!! isset($details['warehouse']['details']) ? __('pos.warehouse_name').':  ' . $details['warehouse']['details'] : '' !!}
            </div>
        </tbody>
    </table>
    <div class=" text-black text-left fs-5 mt-0 mb-0">{{__('Items')}}</div>
        @foreach ($sales['data'] as $key => $value)
            <div class="mt-2">
                <div class="p-0"> <b>{{ $value['name'] }}</b></div>
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
        <div class="d-flex product-border mb-2">
            <div><b>{{ __('pos.total_colon') }}</b></div>
            <div class="text-end ms-auto"> {{ $sales['total'] }}</div>
        </div>

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

        <h5 class="text-center mt-3 font-label">{{ __('pos.thank_you') }}</h5>
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




