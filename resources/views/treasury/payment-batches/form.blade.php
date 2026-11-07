@extends('layouts.admin')
@section('page-title')
    {{ __('New Payment Batch') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-payment-batches.index') }}">{{ __('Payment Batches') }}</a></li>
    <li class="breadcrumb-item">{{ __('New') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($requests->isEmpty())
                        <p class="text-muted">{{ __('No approved payment requests are available to batch. Approve a payment request first.') }}</p>
                        <a href="{{ route('treasury-payment-batches.index') }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    @else
                        <form method="POST" action="{{ route('treasury-payment-batches.store') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">{{ __('Note') }}</label>
                                    <input type="text" name="note" class="form-control" value="{{ old('note') }}" maxlength="1000">
                                </div>
                            </div>
                            <p class="text-muted">{{ __('Each item settles or fails on its own. A failed item keeps the successful items settled and stays in the batch for review.') }}</p>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Beneficiary') }}</th>
                                        <th>{{ __('Remaining') }}</th>
                                        <th>{{ __('Amount to execute') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($requests as $index => $request)
                                        <tr>
                                            <td><input type="checkbox" name="payment_request_id[{{ $index }}]" value="{{ $request->id }}" {{ old('payment_request_id.' . $index) ? 'checked' : '' }}></td>
                                            <td>{{ $request->operation->reference ?? __('Draft') }}</td>
                                            <td>{{ $request->beneficiaryLabel() }}</td>
                                            <td>{{ number_format((float) $request->remainingAmountOriginal(), 2) }} {{ $request->currency }}</td>
                                            <td>
                                                <input type="number" step="0.0001" min="0.0001" max="{{ $request->remainingAmountOriginal() }}" name="amount_original[{{ $index }}]" class="form-control" value="{{ old('amount_original.' . $index, $request->remainingAmountOriginal()) }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <button type="submit" class="btn btn-primary">{{ __('Create batch') }}</button>
                            <a href="{{ route('treasury-payment-batches.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
