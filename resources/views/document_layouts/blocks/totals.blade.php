@php $settings = $settings ?? ($payload['settings'] ?? []); @endphp
<table>
    <tr>
        <td>{{ __('Sub Total') }}</td>
        <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $payload['sub_total'] ?? 0) }}</td>
    </tr>
    @if(($payload['discount_total'] ?? 0) > 0)
        <tr>
            <td>{{ __('Discount') }}</td>
            <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $payload['discount_total']) }}</td>
        </tr>
    @endif
    <tr>
        <td>{{ __('Tax') }}</td>
        <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $payload['tax_total'] ?? 0) }}</td>
    </tr>
    <tr>
        <td><strong>{{ __('Total') }}</strong></td>
        <td class="text-end"><strong>{{ \App\Models\Utility::priceFormat($settings, $payload['grand_total'] ?? 0) }}</strong></td>
    </tr>
</table>
