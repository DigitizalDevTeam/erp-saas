@php $isMoz = !empty($isMoz); @endphp
@if($isMoz)
    <div class="dl-company-box">
        <strong>{{ $settings['company_name'] ?? '' }}</strong>
        <div class="dl-muted" style="margin-top:4px;">
            @if(!empty($settings['company_address'])){{ $settings['company_address'] }}@endif
            @if(!empty($settings['company_city'])){{ !empty($settings['company_address']) ? ', ' : '' }}{{ $settings['company_city'] }}@endif
            @if(!empty($settings['company_state'])){{ ', ' . $settings['company_state'] }}@endif
            @if(!empty($settings['company_zipcode'])){{ ' ' . $settings['company_zipcode'] }}@endif
            @if(!empty($settings['company_country'])){{ ', ' . $settings['company_country'] }}@endif
            <br>
            @if(!empty($settings['company_telephone'])){{ __('Tel.') }}: {{ $settings['company_telephone'] }}@endif
            @if(!empty($settings['vat_number'])) · {{ __('NUIT') }}: {{ $settings['vat_number'] }}@endif
        </div>
    </div>
@else
    <div>
        <h4>{{ $settings['company_name'] ?? '' }}</h4>
        <div class="dl-muted">
            @if(!empty($settings['company_address'])){{ $settings['company_address'] }}<br>@endif
            @if(!empty($settings['company_city']) || !empty($settings['company_state']))
                {{ trim(($settings['company_city'] ?? '') . ' ' . ($settings['company_state'] ?? '')) }}<br>
            @endif
            @if(!empty($settings['company_telephone'])){{ __('Phone') }}: {{ $settings['company_telephone'] }}<br>@endif
            @if(!empty($settings['company_email'])){{ __('Email') }}: {{ $settings['company_email'] }}@endif
        </div>
    </div>
@endif
