@component('mail::message')
# {{ __('Stock Alerts') }}

@if(!empty($data['min']) && count($data['min']) > 0)
## {{ __('Below Minimum Stock') }}

@component('mail::table')
| {{ __('Product') }} | {{ __('SKU') }} | {{ __('Qty') }} | {{ __('Min') }} |
|:--|:--|--:|--:|
@foreach($data['min'] as $row)
| {{ $row['name'] }} | {{ $row['sku'] }} | {{ $row['qty'] }} | {{ $row['min'] }} |
@endforeach
@endcomponent
@endif

@if(!empty($data['max']) && count($data['max']) > 0)
## {{ __('Above Maximum Stock') }}

@component('mail::table')
| {{ __('Product') }} | {{ __('SKU') }} | {{ __('Qty') }} | {{ __('Max') }} |
|:--|:--|--:|--:|
@foreach($data['max'] as $row)
| {{ $row['name'] }} | {{ $row['sku'] }} | {{ $row['qty'] }} | {{ $row['max'] }} |
@endforeach
@endcomponent
@endif

{{ __('Generated at') }}: {{ now() }}

{{ __('Regards,') }}  
{{ $settings['company_name'] ?? config('app.name') }}
@endcomponent

