<div class="writeoff-doc">
    <h2>{{ __('Warehouse Write-off Document') }}</h2>
    <div class="muted">{{ __('For damaged / deteriorated / expired / missing goods') }}</div>

    <div class="box">
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Write-off #') }}</div>
                <div class="value">{{ $writeOffNo }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Date') }}</div>
                <div class="value">{{ \Auth::user()->dateFormat($record->date) }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Warehouse') }}</div>
                <div class="value">{{ $record->warehouse->name ?? '-' }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Reason') }}</div>
                <div class="value">{{ $record->reason ?? '-' }}</div>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="label">{{ __('Delivery Note Required') }}</div>
                <div class="value">{{ (int) ($record->delivery_note_required ?? 0) === 1 ? __('Yes') : __('No') }}</div>
            </div>
            <div class="col">
                <div class="label">{{ __('Destination / Delivery Reference') }}</div>
                <div class="value">{{ $record->delivery_destination ?? '-' }}</div>
            </div>
        </div>

        <table class="items" aria-label="{{ __('Products') }}">
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Product') }}</th>
                    <th class="num">{{ __('Quantity') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lines as $i => $line)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $line->product->name ?? '-' }}</td>
                        <td class="num">{{ $line->quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sign-wrap">
        <div class="sign">
            <div class="line">{{ __('Issued By') }}: {{ $record->issuer_name ?? '-' }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Authorized By') }}: {{ $record->authorized_by ?? '-' }}</div>
        </div>
        <div class="sign">
            <div class="line">{{ __('Witness') }}: {{ $record->witness_name ?? '-' }}</div>
        </div>
    </div>
</div>
