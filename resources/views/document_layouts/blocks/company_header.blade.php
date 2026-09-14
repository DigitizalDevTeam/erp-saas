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
