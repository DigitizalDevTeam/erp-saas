@extends('layouts.admin')
@section('page-title')
    {{ __('End of Day (EOD) Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('End of Day Report') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.pos.eod', 'method' => 'get', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-auto">
                        {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}
                        {{ Form::date('date', $date, ['class' => 'form-control']) }}
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">{{ __('Show') }}</button>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('EOD Summary') }} — {{ $date }}</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="border rounded p-3">
                                <div class="text-muted small">{{ __('Total Sales') }}</div>
                                <div class="h4 mb-0">{{ \Auth::user()->priceFormat($totalSales) }}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3">
                                <div class="text-muted small">{{ __('Cash') }}</div>
                                <div class="h4 mb-0">{{ \Auth::user()->priceFormat($cashTotal) }}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3">
                                <div class="text-muted small">{{ __('Card') }}</div>
                                <div class="h4 mb-0">{{ \Auth::user()->priceFormat($cardTotal) }}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-3">
                                <div class="text-muted small">{{ __('Credit') }}</div>
                                <div class="h4 mb-0">{{ \Auth::user()->priceFormat($creditTotal) }}</div>
                            </div>
                        </div>
                    </div>

                    <h6 class="mb-2">{{ __('Closed Shifts') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Opened') }}</th>
                                    <th>{{ __('Closed') }}</th>
                                    <th class="text-end">{{ __('Sales Total') }}</th>
                                    <th class="text-end">{{ __('Cash') }}</th>
                                    <th class="text-end">{{ __('Card') }}</th>
                                    <th class="text-end">{{ __('Credit') }}</th>
                                    <th class="text-end">{{ __('Difference') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($shifts as $s)
                                    <tr>
                                        <td>{{ $s->branch ? $s->branch->name : '-' }}</td>
                                        <td>{{ $s->user ? $s->user->name : '-' }}</td>
                                        <td>{{ $s->opened_at ? $s->opened_at->format('H:i') : '-' }}</td>
                                        <td>{{ $s->closed_at ? $s->closed_at->format('H:i') : '-' }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($s->sales_total) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($s->cash_total) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($s->card_total) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($s->credit_total) }}</td>
                                        <td class="text-end">{{ $s->difference !== null ? \Auth::user()->priceFormat($s->difference) : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted">{{ __('No closed shifts for this date.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if(count($categorySummary) > 0)
                        <h6 class="mt-4 mb-2">{{ __('Sales by Category') }}</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Category') }}</th>
                                        <th class="text-end">{{ __('Quantity') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($categorySummary as $catName => $row)
                                        <tr>
                                            <td>{{ $catName }}</td>
                                            <td class="text-end">{{ (int) $row['qty'] }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['amount']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
