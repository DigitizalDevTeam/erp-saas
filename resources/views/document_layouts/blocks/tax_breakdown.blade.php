@php
    $settings = $settings ?? ($payload['settings'] ?? []);
    $taxTotal = (float) ($payload['tax_total'] ?? 0);
@endphp
<div>
    <h4>{{ __('Tax breakdown') }}</h4>
    <table>
        <tr>
            <td>{{ __('Total tax') }}</td>
            <td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $taxTotal) }}</td>
        </tr>
    </table>
</div>
