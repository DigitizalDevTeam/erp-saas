@php
    $banks = $payload['banks'] ?? collect();
    $limit = max(1, min(5, (int) ($options['limit'] ?? 2)));
    $fields = $options['fields'] ?? ['holder_name', 'bank_name', 'account_number', 'branch', 'swift'];
    $banks = $banks->take($limit);
@endphp
<div>
    <h4>{{ __('Bank account details') }}</h4>
    @forelse($banks as $bank)
        <div style="margin-bottom:8px;">
            @if(in_array('holder_name', $fields, true) && !empty($bank->holder_name))
                <strong>{{ $bank->holder_name }}</strong><br>
            @endif
            <div class="dl-muted">
                @if(in_array('bank_name', $fields, true) && !empty($bank->bank_name))
                    {{ __('Bank Name') }}: {{ $bank->bank_name }}<br>
                @endif
                @if(in_array('account_number', $fields, true) && !empty($bank->account_number))
                    {{ __('Account Number') }}: {{ $bank->account_number }}<br>
                @endif
                @if(in_array('branch', $fields, true) && !empty($bank->bank_address))
                    {{ __('Branch Code') }}: {{ $bank->bank_address }}<br>
                @endif
                @if(in_array('swift', $fields, true) && !empty($bank->contact_number))
                    {{ __('SWIFT Code') }}: {{ $bank->contact_number }}
                @endif
            </div>
        </div>
    @empty
        <span class="dl-muted">{{ __('No bank accounts configured.') }}</span>
    @endforelse
</div>
