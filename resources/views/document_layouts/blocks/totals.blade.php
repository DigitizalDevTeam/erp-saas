@php
    $settings = $settings ?? ($payload['settings'] ?? []);
    $isMoz = !empty($isMoz);
    $grand = $payload['grand_total'] ?? 0;
@endphp
@if($isMoz)
    <div class="dl-due-box">
        <div class="dl-due-label">{{ __('Due') }}:</div>
        <div class="dl-due-amount">{{ \App\Models\Utility::priceFormat($settings, $grand) }}</div>
        <table>
            @if(!empty($payload['date']))
                <tr><td class="dl-muted">{{ __('Date') }}</td><td class="text-end">{{ \Auth::check() ? \Auth::user()->dateFormat($payload['date']) : $payload['date'] }}</td></tr>
            @endif
            @if(!empty($payload['due_date']))
                <tr><td class="dl-muted">{{ __('Due Date') }}</td><td class="text-end">{{ \Auth::check() ? \Auth::user()->dateFormat($payload['due_date']) : $payload['due_date'] }}</td></tr>
            @endif
            <tr><td class="dl-muted">{{ __('Sub Total') }}</td><td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $payload['sub_total'] ?? 0) }}</td></tr>
            <tr><td class="dl-muted">{{ __('Tax') }}</td><td class="text-end">{{ \App\Models\Utility::priceFormat($settings, $payload['tax_total'] ?? 0) }}</td></tr>
            <tr><td><strong>{{ __('Total') }}</strong></td><td class="text-end"><strong>{{ \App\Models\Utility::priceFormat($settings, $grand) }}</strong></td></tr>
        </table>
    </div>
@else
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
            <td class="text-end"><strong>{{ \App\Models\Utility::priceFormat($settings, $grand) }}</strong></td>
        </tr>
    </table>
@endif
