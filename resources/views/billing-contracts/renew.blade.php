@extends('layouts.admin')
@section('page-title')
    {{ __('Renew contract') }} — {{ $contract->reference }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.index') }}">{{ __('Billing Contracts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('billing-contracts.show', $contract->id) }}">{{ $contract->reference }}</a></li>
    <li class="breadcrumb-item">{{ __('Renew') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('The renewal is a fresh contract draft with the same customer, lines, frequency and terms — a new reference, a new occurrence stream starting at the new start date. Periods already invoiced under this contract are never billed again. The renewal needs the same approval as a new contract.') }}</small></p>
                    <dl class="row">
                        <dt class="col-sm-4">{{ __('Renewing') }}</dt>
                        <dd class="col-sm-8">{{ $contract->reference }} — {{ $contract->title }}</dd>
                        <dt class="col-sm-4">{{ __('Customer') }}</dt>
                        <dd class="col-sm-8">{{ $contract->customer->name ?? '—' }}</dd>
                        <dt class="col-sm-4">{{ __('Current term') }}</dt>
                        <dd class="col-sm-8">{{ $contract->start_date->format('Y-m-d') }} → {{ $contract->end_date->format('Y-m-d') }}</dd>
                        <dt class="col-sm-4">{{ __('Schedule') }}</dt>
                        <dd class="col-sm-8">{{ ucfirst($contract->billing_frequency) }} · {{ __('anchor day') }} {{ $contract->anchor_day }} · {{ $contract->billing_mode === 'advance' ? __('in advance') : __('in arrears') }}</dd>
                        <dt class="col-sm-4">{{ __('Per period') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $contract->lines->sum('amount'), 2) }} {{ $contract->currency }}</dd>
                    </dl>
                    <form method="POST" action="{{ route('billing-contracts.renew', $contract->id) }}">
                        @csrf
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('Title') }}</label>
                            <input type="text" name="title" class="form-control" value="{{ old('title', $contract->title) }}" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group mb-3">
                                <label class="form-label">{{ __('New start date') }}</label>
                                <input type="date" name="start_date" class="form-control" value="{{ old('start_date', $newStart) }}" required>
                                <small class="text-muted">{{ __('Default: the day after the current contract ends.') }}</small>
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label class="form-label">{{ __('New end date') }}</label>
                                <input type="date" name="end_date" class="form-control" value="{{ old('end_date', $newEnd) }}">
                                <small class="text-muted">{{ __('Default: same term length as the current contract. Leave empty for open-ended.') }}</small>
                            </div>
                        </div>
                        <button class="btn btn-success" type="submit">{{ __('Create renewal draft') }}</button>
                        <a href="{{ route('billing-contracts.show', $contract->id) }}" class="btn btn-light">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
