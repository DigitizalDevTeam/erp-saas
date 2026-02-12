@extends('layouts.admin')
@section('page-title')
    {{ __('Shift Report') }} #{{ $pos_shift->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.show', $pos_shift) }}">{{ __('Shift') }} #{{ $pos_shift->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Report') }}</li>
@endsection

@section('action-btn')
    <button type="button" class="btn btn-sm btn-primary" onclick="window.print();"><i class="ti ti-printer"></i> {{ __('Print') }}</button>
    <a href="{{ route('pos-shift.show', $pos_shift) }}" class="btn btn-sm btn-secondary">{{ __('Back') }}</a>
@endsection

@section('content')
    <div id="shift-report-printable" class="card">
        <div class="card-header">
            <h5 class="mb-0">{{ __('Shift Report') }} — {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }} / {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <p class="mb-1"><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('User') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Opened At') }}:</strong> {{ $pos_shift->opened_at ? $pos_shift->opened_at->format('Y-m-d H:i') : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Opening Balance') }}:</strong> {{ \Auth::user()->priceFormat($pos_shift->opening_balance ?? 0) }}</p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>{{ __('Status') }}:</strong> {{ $pos_shift->status === 'open' ? __('Open') : __('Closed') }}</p>
                    @if($pos_shift->closed_at)
                        <p class="mb-1"><strong>{{ __('Closed At') }}:</strong> {{ $pos_shift->closed_at->format('Y-m-d H:i') }}</p>
                        <p class="mb-1"><strong>{{ __('Closing Balance') }}:</strong> {{ \Auth::user()->priceFormat($pos_shift->closing_balance ?? 0) }}</p>
                    @endif
                    <p class="mb-1"><strong>{{ __('Sales Total') }}:</strong> {{ \Auth::user()->priceFormat($salesTotal) }}</p>
                </div>
            </div>

            <h6 class="border-bottom pb-2 mb-2">{{ __('Payment Summary') }}</h6>
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>{{ __('Payment Method') }}</th>
                        <th class="text-end">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>{{ __('pos.payment_cash') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($cashTotal) }}</td></tr>
                    <tr><td>{{ __('Card / Other') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($cardTotal) }}</td></tr>
                    <tr><td>{{ __('pos.payment_credit') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($creditTotal) }}</td></tr>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td>{{ __('Total') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($cashTotal + $cardTotal + $creditTotal) }}</td>
                    </tr>
                </tfoot>
            </table>

            <h6 class="border-bottom pb-2 mb-2 mt-4">{{ __('Sales') }} ({{ $sales->count() }} {{ __('transactions') }})</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>{{ __('pos.pos_id') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th class="text-end">{{ __('pos.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $pos)
                            <tr>
                                <td>{{ \Auth::user()->posNumberFormat($pos->pos_id) }}</td>
                                <td>{{ $pos->pos_date ?? '-' }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat($pos->getTotal()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
