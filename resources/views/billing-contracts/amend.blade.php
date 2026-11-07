@extends('layouts.admin')
@section('page-title')
    {{ __('Amend Contract') }} — {{ $contract->reference }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></li>
    <li class="breadcrumb-item">{{ __('Amend') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('An amendment snapshots the current lines, schedule and terms into a new revision, then applies your changes from the effective date. Only future pending occurrences are regenerated — issued invoices never change.') }}</small></p>
                    <form method="POST" action="{{ route('billing-contracts.amend', $contract->id) }}" id="billing-contract-form">
                        @csrf
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Effective date') }} *</label>
                                    <input type="date" name="effective_date" class="form-control" value="{{ old('effective_date', now()->format('Y-m-d')) }}" required>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <div class="form-group">
                                    <label class="form-label">{{ __('Reason') }} *</label>
                                    <input type="text" name="reason" class="form-control" value="{{ old('reason') }}" placeholder="{{ __('Why is this contract changing?') }}" required>
                                </div>
                            </div>
                        </div>
                        @include('billing-contracts._form', ['contract' => $contract])

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save amendment') }}</button>
                            <a href="{{ route('billing-contracts.show', $contract->id) }}" class="btn btn-light">{{ __('Cancel') }}</a>
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
</script>
@endpush
