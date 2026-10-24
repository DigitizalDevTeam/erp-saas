@component('mail::message')
# {{ __('Low Stock Report by Warehouse') }}

@if(!empty($data['min']) && count($data['min']) > 0)
## {{ __('Below Minimum Stock') }}

@component('mail::table')
| {{ __('Product') }} | {{ __('Warehouse') }} | {{ __('SKU') }} | {{ __('Qty') }} | {{ __('Min') }} |
|:--|:--|:--|--:|--:|
@foreach($data['min'] as $row)
| {{ $row['name'] }} | {{ $row['warehouse'] }} | {{ $row['sku'] }} | {{ $row['qty'] }} | {{ $row['min'] }} |
@endforeach
@endcomponent
@endif

{{ __('Generated at') }}: {{ now() }}

{{ __('Regards,') }}  
{{ $settings['company_name'] ?? config('app.name') }}
@endcomponent
