@php
    $party = $payload['party'] ?? null;
    $isMoz = !empty($isMoz);
    $name = $party->name ?? ($party->billing_name ?? ($party->shipping_name ?? '—'));
    $billAddr = $party->billing_address ?? ($party->address ?? '—');
    $shipAddr = $party->shipping_address ?? $billAddr;
    $shipName = $party->shipping_name ?? $name;
@endphp
@if($isMoz)
    <div class="dl-party-grid">
        <div class="dl-party-col">
            <h4>{{ __('Invoice To') }}</h4>
            <strong>{{ __('Customer Name') }} :</strong> {{ $name }}<br>
            <div class="dl-muted">
                @if(!empty($party->customer_id) || !empty($party->id))
                    {{ __('Code') }}: {{ $party->customer_id ?? $party->id }}<br>
                @endif
                @if(!empty($party->tax_number)){{ __('VAT No') }}: {{ $party->tax_number }}<br>@endif
                {{ __('Address') }}: {{ $billAddr }}
            </div>
        </div>
        <div class="dl-party-col">
            <h4>{{ __('Ship To') }}</h4>
            <strong>{{ __('Customer Name') }} :</strong> {{ $shipName }}<br>
            <div class="dl-muted">{{ __('Address') }}: {{ $shipAddr }}</div>
        </div>
    </div>
@else
    <div>
        <h4>{{ $payload['party_label'] ?? __('Party') }}</h4>
        @if($party)
            <strong>{{ $name }}</strong><br>
            <div class="dl-muted">
                @if($billAddr !== '—'){{ $billAddr }}<br>@endif
                @if(!empty($party->email)){{ $party->email }}<br>@endif
                @if(!empty($party->contact) || !empty($party->phone) || !empty($party->billing_phone))
                    {{ $party->contact ?? ($party->billing_phone ?? $party->phone) }}
                @endif
            </div>
        @else
            <span class="dl-muted">—</span>
        @endif
    </div>
@endif
