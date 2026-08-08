{{-- Shared invoice document references (PO + quotation). --}}
@if(!empty($invoice->client_po_number) || !empty($invoice->quotation_reference) || !empty($invoice->purchase_id) || !empty($invoice->client_po_date) || !empty($invoice->client_po_details))
    @if(!empty($invoice->client_po_number))
        <tr>
            <td>{{ __('PO Number') }}:</td>
            <td class="text-right">{{ $invoice->client_po_number }}</td>
        </tr>
    @endif
    @if(!empty($invoice->client_po_date))
        <tr>
            <td>{{ __('PO Date') }}:</td>
            <td class="text-right">
                @isset($settings)
                    {{ \App\Models\Utility::dateFormat($settings, $invoice->client_po_date) }}
                @else
                    {{ $invoice->client_po_date }}
                @endisset
            </td>
        </tr>
    @endif
    @if(!empty($invoice->quotation_reference))
        <tr>
            <td>{{ __('Quotation Reference') }}:</td>
            <td class="text-right">{{ $invoice->quotation_reference }}</td>
        </tr>
    @endif
    @if(!empty($invoice->purchase_id) && !empty($invoice->sourcePurchase))
        <tr>
            <td>{{ __('Purchase Order') }}:</td>
            <td class="text-right">
                {{ \App\Models\Utility::purchaseNumberFormat($invoice->sourcePurchase->purchase_id) }}
            </td>
        </tr>
    @endif
    @if(!empty($invoice->client_po_details))
        <tr>
            <td>{{ __('PO Details') }}:</td>
            <td class="text-right">{{ $invoice->client_po_details }}</td>
        </tr>
    @endif
@endif
