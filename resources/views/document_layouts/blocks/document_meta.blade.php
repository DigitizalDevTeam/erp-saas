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
