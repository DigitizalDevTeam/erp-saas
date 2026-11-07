@extends('layouts.admin')
@section('page-title')
    {{ __('New Billing Contract') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item">{{ __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('billing-contracts.store') }}" id="billing-contract-form">
                        @csrf
                        @include('billing-contracts._form', ['contract' => null])

                        <div id="billing-preview" class="mt-4" style="display:none;">
                            <h6>{{ __('Preview — first 3 occurrences') }}</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Coverage') }}</th>
                                            <th>{{ __('Billing date') }}</th>
                                            <th>{{ __('Due date') }}</th>
                                            <th>{{ __('Amount') }}</th>
                                            <th>{{ __('Note') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody id="billing-preview-body"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary" id="billing-preview-btn">{{ __('Preview first 3 occurrences') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('Create draft') }}</button>
                            <a href="{{ route('billing-contracts.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
document.getElementById('add-billing-line').addEventListener('click', function () {
    const tbody = document.querySelector('#billing-lines-table tbody');
    const index = tbody.rows.length;
    const row = tbody.rows[0].cloneNode(true);
    row.querySelectorAll('input, select').forEach(function (el) {
        el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
        el.value = el.tagName === 'SELECT' ? '' : (el.name.includes('[quantity]') ? '1' : (el.name.includes('[discount_amount]') ? '0' : ''));
    });
    tbody.appendChild(row);
});

document.getElementById('billing-preview-btn').addEventListener('click', function () {
    const form = document.getElementById('billing-contract-form');
    const data = new FormData(form);
    fetch('{{ route('billing-contracts.preview') }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        body: data,
    }).then(function (r) { return r.json(); }).then(function (json) {
        const box = document.getElementById('billing-preview');
        const body = document.getElementById('billing-preview-body');
        if (json.error) {
            body.innerHTML = '<tr><td colspan="5" class="text-danger">' + json.error + '</td></tr>';
        } else {
            body.innerHTML = json.occurrences.map(function (o) {
                return '<tr><td>' + o.period_start + ' → ' + o.period_end + '</td><td>' + (o.billing_date || '—') + '</td><td>' + (o.due_date || '—') + '</td><td>' + o.amount + '</td><td>' + (o.skipped ? 'Skipped (partial period)' : (o.partial ? 'Partial period' : '')) + '</td></tr>';
            }).join('');
        }
        box.style.display = 'block';
    });
});
</script>
@endpush
