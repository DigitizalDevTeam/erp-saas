@php $isMoz = !empty($isMoz); @endphp
@if($isMoz)
    <div class="dl-doc-badge">
        <h4>{{ $payload['title'] ?? __('Invoice') }}</h4>
        <div class="dl-doc-num">#{{ $payload['number'] ?? '—' }}</div>
        @if(!empty($payload['date']))
            <div style="font-size:11px;margin-top:6px;opacity:.95;">{{ \Auth::check() ? \Auth::user()->dateFormat($payload['date']) : $payload['date'] }}</div>
        @endif
    </div>
@else
    <div>
        <h4>{{ $payload['title'] ?? __('Document') }}</h4>
        <table>
            <tr><td class="dl-muted">{{ __('Number') }}</td><td><strong>{{ $payload['number'] ?? '—' }}</strong></td></tr>
            @if(!empty($payload['date']))
                <tr><td class="dl-muted">{{ __('Date') }}</td><td>{{ \Auth::check() ? \Auth::user()->dateFormat($payload['date']) : $payload['date'] }}</td></tr>
            @endif
            @if(!empty($payload['due_date']))
                <tr><td class="dl-muted">{{ __('Due Date') }}</td><td>{{ \Auth::check() ? \Auth::user()->dateFormat($payload['due_date']) : $payload['due_date'] }}</td></tr>
            @endif
            @if(isset($payload['status']) && $payload['status'] !== '' && $payload['status'] !== null)
                <tr><td class="dl-muted">{{ __('Status') }}</td><td>{{ $payload['status'] }}</td></tr>
            @endif
        </table>
    </div>
@endif
