@extends('layouts.admin')
@section('page-title')
    {{ __('Day Summary') }} — {{ __('POS Shifts') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Day Summary') }}</li>
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.pos.day-summary', 'method' => 'get', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-auto">
                        {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}
                        {{ Form::date('date', $date, ['class' => 'form-control']) }}
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">{{ __('Show') }}</button>
                    </div>
                    <div class="col-auto">
                        <a href="{{ route('report.pos.eod', ['date' => $date]) }}" class="btn btn-outline-secondary">{{ __('EOD Report') }}</a>
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
                    <h5 class="mb-0">{{ __('Day Summary') }} — {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</h5>
                </div>
                <div class="card-body">
                    @if($shifts->isEmpty())
                        <p class="text-muted mb-0">{{ __('No shifts for this date.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Shift') }} #</th>
                                        <th>{{ __('Branch') }}</th>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Opened At') }}</th>
                                        <th>{{ __('Closed At') }}</th>
                                        <th class="text-end">{{ __('Opening Balance') }}</th>
                                        <th class="text-end">{{ __('Closing Balance') }}</th>
                                        <th class="text-end">{{ __('Sales Count') }}</th>
                                        <th class="text-end">{{ __('Sales Total') }}</th>
                                        <th class="text-end">{{ __('Cash') }}</th>
                                        <th class="text-end">{{ __('Card') }}</th>
                                        <th class="text-end">{{ __('Credit') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="no-print"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($shifts as $s)
                                        <tr>
                                            <td>{{ $s->id }}</td>
                                            <td>{{ $s->branch ? $s->branch->name : '-' }}</td>
                                            <td>{{ $s->user ? $s->user->name : '-' }}</td>
                                            <td>{{ $s->opened_at ? $s->opened_at->format('H:i') : '-' }}</td>
                                            <td>{{ $s->closed_at ? $s->closed_at->format('H:i') : '-' }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($s->opening_balance ?? 0) }}</td>
                                            <td class="text-end">{{ $s->closed_at ? \Auth::user()->priceFormat($s->closing_balance ?? 0) : '-' }}</td>
                                            <td class="text-end">{{ $s->sales_count ?? 0 }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($s->sales_total ?? 0) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($s->cash_total ?? 0) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($s->card_total ?? 0) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($s->credit_total ?? 0) }}</td>
                                            <td><span class="badge {{ $s->status === 'open' ? 'bg-success' : 'bg-secondary' }}">{{ $s->status === 'open' ? __('Open') : __('Closed') }}</span></td>
                                            <td class="no-print">
                                                <a href="{{ route('pos-shift.show', $s) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                                @if($s->status === 'closed')
                                                    <a href="{{ route('pos-shift.report', $s) }}" class="btn btn-sm btn-outline-info">{{ __('Report') }}</a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="fw-bold">
                                        <td colspan="8" class="text-end">{{ __('Total') }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($shifts->sum('sales_total')) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($shifts->sum('cash_total')) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($shifts->sum('card_total')) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($shifts->sum('credit_total')) }}</td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
