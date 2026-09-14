@php $party = $payload['party'] ?? null; @endphp
<div>
    <h4>{{ $payload['party_label'] ?? __('Party') }}</h4>
    @if($party)
        <strong>{{ $party->name ?? ($party->billing_name ?? '') }}</strong><br>
        <div class="dl-muted">
            @if(!empty($party->billing_address) || !empty($party->address))
                {{ $party->billing_address ?? $party->address }}<br>
            @endif
            @if(!empty($party->email)){{ $party->email }}<br>@endif
            @if(!empty($party->contact) || !empty($party->phone))
                {{ $party->contact ?? $party->phone }}
            @endif
        </div>
    @else
        <span class="dl-muted">—</span>
    @endif
</div>
