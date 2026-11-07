@extends('layouts.admin')
@section('page-title')
    {{ __('Confirm Transfer') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-transfers.index') }}">{{ __('Transfers') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-transfers.show', $preview['transfer']) }}">{{ __('Draft') }}</a></li>
    <li class="breadcrumb-item">{{ __('Confirm') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Transfer') }}</h6>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('From') }}</dt>
                        <dd class="col-sm-8">{{ $preview['source']->displayName() }} — {{ $preview['transfer']->source_currency }}</dd>
                        <dt class="col-sm-4">{{ __('To') }}</dt>
                        <dd class="col-sm-8">{{ $preview['destination']->displayName() }} — {{ $preview['transfer']->destination_currency }}</dd>
                        @if ($preview['transit'])
                            <dt class="col-sm-4">{{ __('Transit') }}</dt>
                            <dd class="col-sm-8">{{ $preview['transit']->displayName() }} — {{ $preview['transit']->currency }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Amount Debited') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $preview['amounts']['amount_debited'], 2) }} {{ $preview['transfer']->source_currency }}</dd>
                        <dt class="col-sm-4">{{ __('Amount Credited') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $preview['amounts']['amount_credited'], 2) }} {{ $preview['transfer']->destination_currency }}</dd>
                        @if ($preview['transfer']->source_currency !== $preview['transfer']->destination_currency)
                            <dt class="col-sm-4">{{ __('Exchange Rate') }}</dt>
                            <dd class="col-sm-8">{{ $preview['amounts']['exchange_rate'] }} ({{ $preview['transfer']->rate_source ?? __('manual') }})</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Fee') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $preview['amounts']['fee_base'], 2) }} {{ __('(base currency)') }}</dd>
                        <dt class="col-sm-4">{{ __('Posting Date') }}</dt>
                        <dd class="col-sm-8">{{ $preview['posting_date'] }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Checks Before Confirm') }}</h6>
                    <table class="table table-sm">
                        <tbody>
                            @foreach ($preview['checks'] as $check)
                                <tr>
                                    <td style="width: 30px;">{!! $check['passed'] ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-danger">✕</span>' !!}</td>
                                    <td>{{ $check['detail'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($preview['can_confirm'])
                        <form method="POST" action="{{ route('treasury-transfers.confirm', $preview['transfer']) }}">
                            @csrf
                            <input type="hidden" name="posting_date" value="{{ $preview['posting_date'] }}">
                            <button type="submit" class="btn btn-primary">{{ $preview['transfer']->isDelayed() ? __('Confirm — Move to Transit') : __('Confirm Transfer') }}</button>
                            <a href="{{ route('treasury-transfers.show', $preview['transfer']) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                        </form>
                    @else
                        <p class="text-danger">{{ __('This transfer cannot be confirmed until the failed checks are fixed.') }}</p>
                        <a href="{{ route('treasury-transfers.show', $preview['transfer']) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Source Balance') }}</h6>
                    <dl class="row mb-0">
                        <dt class="col-sm-6">{{ __('Posted') }}</dt>
                        <dd class="col-sm-6 text-end">{{ number_format((float) $preview['posted_balance'], 2) }}</dd>
                        <dt class="col-sm-6">{{ __('Available') }}</dt>
                        <dd class="col-sm-6 text-end">{{ number_format((float) $preview['available_balance'], 2) }}</dd>
                        <dt class="col-sm-6">{{ __('Destination') }}</dt>
                        <dd class="col-sm-6 text-end">{{ number_format((float) $preview['destination_balance'], 2) }}</dd>
                        @if ($preview['transit_balance'] !== null)
                            <dt class="col-sm-6">{{ __('Transit') }}</dt>
                            <dd class="col-sm-6 text-end">{{ number_format((float) $preview['transit_balance'], 2) }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
